<?php
require __DIR__.'/bootstrap/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
echo "Attachments: ".\App\Models\Attachment::count()."\n";
echo "TicketAttachments: ".\App\Models\TicketAttachment::count()."\n";
?>