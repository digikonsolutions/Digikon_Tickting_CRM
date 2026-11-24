<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketChecklistItem;
use App\Models\TicketDetail;
use App\Models\User;
use App\Support\TicketWorkOrder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * The work order of a ticket. Never touches hours, position, status or the planner: details are information only.
 * Agency users write the work order, the assigned employee writes delivery, the blocked flag and ticks the checklist.
 * Always called inside the request's own transaction.
 */
class TicketDetailsService
{
    public const MAX_CHECKLIST = 50;

    public function __construct(private TicketTemplateRegistry $templates) {}

    public function canSeeInternal(?User $viewer): bool
    {
        return $viewer !== null && in_array($viewer->role, [Role::Admin, Role::Employee], true);
    }

    private function row(Ticket $ticket): TicketDetail
    {
        // ticket_id is guarded (never mass-assigned), so a new row gets it set explicitly.
        return TicketDetail::query()->where('ticket_id', $ticket->id)->first() ?? tap(new TicketDetail, fn (TicketDetail $new) => $new->ticket_id = $ticket->id);
    }

    /**
     * Agency write: the validated `work_order`. On a new work order, a work type pre-fills the template's acceptance
     * criteria and checklist unless the request supplied its own.
     *
     * @param  array<string, mixed>  $order
     */
    public function save(Ticket $ticket, array $order, string $source = 'manual'): TicketDetail
    {
        $row = $this->row($ticket);
        $isNew = ! $row->exists;
        $row->fill(TicketWorkOrder::toColumns($order));

        $template = $this->templates->find($row->work_type);
        if ($isNew && $template !== null) {
            $requirements = $row->requirements ?? [];
            if (blank($requirements['acceptance_criteria'] ?? null) && filled($template['acceptance'])) {
                $row->requirements = [...$requirements, 'acceptance_criteria' => $template['acceptance']];
            }
            if (! array_key_exists('checklist', $order) && $template['checklist'] !== []) {
                $order['checklist'] = array_map(fn (string $text): array => ['text' => $text], $template['checklist']);
            }
        }
        $row->source = $source;
        $row->save();

        if (array_key_exists('checklist', $order)) {
            $this->syncChecklist($ticket, $row, $order['checklist'] ?? []);
        }
        $this->refresh($ticket, $row);

        return $row;
    }

    /** Replaces the ordered list: items with an id keep their progress, new ones start undone, missing ones go. */
    private function syncChecklist(Ticket $ticket, TicketDetail $row, array $items): void
    {
        if (count($items) > self::MAX_CHECKLIST) {
            throw ValidationException::withMessages(['work_order.checklist' => 'A checklist can have at most '.self::MAX_CHECKLIST.' items.']);
        }
        $existing = TicketChecklistItem::query()->where('ticket_id', $ticket->id)->get()->keyBy('id');
        $keep = [];
        foreach (array_values($items) as $position => $item) {
            $found = isset($item['id']) ? $existing->get((int) $item['id']) : null;
            if ($found !== null) {
                $found->update(['text' => $item['text'], 'position' => $position + 1]);
                $keep[] = $found->id;
            } else {
                $keep[] = $ticket->checklistItems()->create(['position' => $position + 1, 'text' => $item['text']])->id;
            }
        }
        TicketChecklistItem::query()->where('ticket_id', $ticket->id)->whereNotIn('id', $keep)->delete();
    }

    /** Recounts the checklist and recomputes completeness; called after every change. */
    public function refresh(Ticket $ticket, TicketDetail $row): void
    {
        $items = TicketChecklistItem::query()->where('ticket_id', $ticket->id)->get(['done']);
        $row->checklist_total = $items->count();
        $row->checklist_done = $items->where('done', true)->count();
        $assembled = TicketWorkOrder::assemble($row, false);
        $assembled['checklist'] = $row->checklist_total > 0 ? [true] : [];
        $row->completeness = TicketWorkOrder::completeness($assembled, $row->work_type)['percent'];
        $row->save();
    }

    /** @param  array<string, mixed>  $delivery */
    public function deliver(Ticket $ticket, array $delivery): TicketDetail
    {
        $row = $this->row($ticket);
        $row->delivery = array_filter($delivery, fn ($value) => $value !== null && $value !== '' && $value !== []);
        $row->delivery_submitted_at = filled($row->delivery['done'] ?? null) ? now() : null;
        $row->save();
        $this->refresh($ticket, $row);

        return $row;
    }

    public function setBlocked(Ticket $ticket, bool $blocked, ?string $reason, ?string $waitingOn): TicketDetail
    {
        $row = $this->row($ticket);
        $row->blocked = $blocked;
        $row->blocked_reason = $blocked ? $reason : null;
        $row->waiting_on = $blocked ? ($waitingOn ?? $row->waiting_on) : ($waitingOn ?? $row->waiting_on);
        $row->save();
        $this->refresh($ticket, $row);

        return $row;
    }

    public function toggle(Ticket $ticket, TicketChecklistItem $item, bool $done, User $actor): TicketChecklistItem
    {
        $item->update(['done' => $done, 'done_by' => $done ? $actor->id : null, 'done_at' => $done ? now() : null]);
        $row = $this->row($ticket);
        $row->save();
        $this->refresh($ticket, $row);

        return $item;
    }

    /**
     * What a duplicate starts with: the agency-written parts of the work order, a fresh undone checklist.
     *
     * @return array<string, mixed>|null
     */
    public function copyOrder(Ticket $source): ?array
    {
        $row = $source->detail;
        if ($row === null) {
            return null;
        }
        $order = Arr::only(TicketWorkOrder::assemble($row, false), ['work_type', 'brief_section', 'labels', 'requirements', 'ghl', 'inputs', 'dependencies', 'environment', 'testing']);
        $order['notes'] = ['client' => $row->notes_client];
        $order['checklist'] = TicketChecklistItem::query()->where('ticket_id', $source->id)->orderBy('position')->get()
            ->map(fn (TicketChecklistItem $item): array => ['text' => $item->text])->all();

        return $order;
    }

    public function saveInternalNotes(Ticket $ticket, ?string $notes): TicketDetail
    {
        $row = $this->row($ticket);
        $row->notes_internal = $notes;
        $row->save();

        return $row;
    }

    /** A work order the agency actually wrote (as opposed to a row made only by an employee's delivery or blocked note). */
    public function hasWorkOrder(?TicketDetail $row): bool
    {
        return $row !== null && ($row->work_type !== null || $row->checklist_total > 0 || filled($row->requirements) || filled($row->ghl_refs));
    }

    /**
     * Sending a ticket with a work order for review needs what was done and at least one link.
     *
     * @throws ValidationException
     */
    public function assertDeliverable(Ticket $ticket): void
    {
        $row = TicketDetail::query()->where('ticket_id', $ticket->id)->first();
        if (! $this->hasWorkOrder($row)) {
            return;
        }
        $delivery = $row->delivery ?? [];
        if (blank($delivery['done'] ?? null) || count($delivery['links'] ?? []) < 1) {
            throw ValidationException::withMessages([
                'delivery' => 'Before sending this for review, add what you did and at least one link to the delivered work.',
            ]);
        }
    }

    /**
     * What lists show for a ticket.
     *
     * @return array<string, mixed>|null
     */
    public function summary(Ticket $ticket): ?array
    {
        $row = $ticket->detail;
        if ($row === null) {
            return null;
        }

        return [
            'work_type' => $row->work_type,
            'work_type_label' => $this->templates->find($row->work_type)['label'] ?? null,
            'checklist_done' => $row->checklist_done,
            'checklist_total' => $row->checklist_total,
            'completeness' => $row->completeness,
            'blocked' => $row->blocked,
            'inputs_ready' => $row->inputs_ready,
            'has_delivery' => $row->delivery_submitted_at !== null,
        ];
    }

    /**
     * The whole work order for a detail view, with the checklist and what is still missing.
     *
     * @return array<string, mixed>|null
     */
    public function full(Ticket $ticket, ?User $viewer): ?array
    {
        $row = $ticket->detail;
        if ($row === null) {
            return null;
        }
        $order = TicketWorkOrder::assemble($row, $this->canSeeInternal($viewer));
        $checklist = TicketChecklistItem::query()->where('ticket_id', $ticket->id)->orderBy('position')->get()
            ->map(fn (TicketChecklistItem $item): array => ['id' => $item->id, 'text' => $item->text, 'done' => $item->done, 'done_at' => $item->done_at?->toISOString()])->all();
        $forScore = $order;
        $forScore['checklist'] = $checklist;

        return $order + [
            'checklist' => $checklist,
            'completeness' => TicketWorkOrder::completeness($forScore, $row->work_type),
            'source' => $row->source,
            'updated_at' => $row->updated_at?->toISOString(),
        ];
    }
}
