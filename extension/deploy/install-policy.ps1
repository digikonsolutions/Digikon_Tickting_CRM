<#
.SYNOPSIS
  Force-installs the ClientHub extension on this Windows computer for Chrome and/or Edge, and sets its API address.

.DESCRIPTION
  Writes the browser's own enterprise policy (ExtensionInstallForcelist plus the extension's managed storage). After a
  browser restart the extension is installed, pinned and cannot be removed by the user; the API address is fixed.
  Run in an elevated PowerShell. For many computers, push the same policy with Group Policy or your MDM instead.

.EXAMPLE
  # Chrome Web Store (unlisted) listing:
  .\install-policy.ps1 -ExtensionId abcdefghijklmnopabcdefghijklmnop -ApiBase https://api.clienthub.example.com/api

.EXAMPLE
  # Self-hosted update.xml (needs a managed / domain-joined computer):
  .\install-policy.ps1 -ExtensionId ... -ApiBase ... -UpdateUrl https://updates.clienthub.example.com/extension/update.xml

.EXAMPLE
  .\install-policy.ps1 -ExtensionId ... -Remove
#>
param(
  [Parameter(Mandatory)][ValidatePattern('^[a-p]{32}$')][string]$ExtensionId,
  [string]$ApiBase,
  [string]$UpdateUrl = 'https://clients2.google.com/service/update2/crx',
  [ValidateSet('Chrome', 'Edge', 'Both')][string]$Browser = 'Both',
  [switch]$Remove
)
$ErrorActionPreference = 'Stop'
if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Run this in an elevated (administrator) PowerShell.' }
if (-not $Remove) {
  if (-not $ApiBase) { throw 'Give -ApiBase, the https API address.' }
  if ($ApiBase -notmatch '^https://') { throw '-ApiBase must start with https://' }
}

$targets = @()
if ($Browser -in 'Chrome', 'Both') { $targets += @{ Name = 'Chrome'; Base = 'HKLM:\SOFTWARE\Policies\Google\Chrome'; Update = $UpdateUrl } }
# Edge installs from its own add-ons store by default; a self-hosted update.xml works the same way.
if ($Browser -in 'Edge', 'Both') { $targets += @{ Name = 'Edge'; Base = 'HKLM:\SOFTWARE\Policies\Microsoft\Edge'; Update = $(if ($UpdateUrl -like '*clients2.google.com*') { 'https://edge.microsoft.com/extensionwebstorebase/v1/crx' } else { $UpdateUrl }) } }

foreach ($t in $targets) {
  $list = Join-Path $t.Base 'ExtensionInstallForcelist'
  $managed = Join-Path $t.Base "3rdparty\extensions\$ExtensionId\policy"
  $entry = "$ExtensionId;$($t.Update)"
  if ($Remove) {
    if (Test-Path $list) { Get-Item $list | Select-Object -ExpandProperty Property | ForEach-Object { if ((Get-ItemPropertyValue $list $_) -like "$ExtensionId;*") { Remove-ItemProperty $list $_ } } }
    if (Test-Path $managed) { Remove-Item (Join-Path $t.Base "3rdparty\extensions\$ExtensionId") -Recurse -Force }
    Write-Host "$($t.Name): removed."
    continue
  }
  New-Item -Path $list -Force | Out-Null
  $existing = Get-Item $list | Select-Object -ExpandProperty Property
  $already = $existing | Where-Object { (Get-ItemPropertyValue $list $_) -like "$ExtensionId;*" } | Select-Object -First 1
  if ($already) { Set-ItemProperty $list $already $entry } else {
    $next = 1; while ($existing -contains "$next") { $next++ }
    New-ItemProperty -Path $list -Name "$next" -Value $entry -PropertyType String -Force | Out-Null
  }
  New-Item -Path $managed -Force | Out-Null
  New-ItemProperty -Path $managed -Name 'apiBase' -Value $ApiBase -PropertyType String -Force | Out-Null
  Write-Host "$($t.Name): policy written. Restart the browser, then open $(if ($t.Name -eq 'Chrome') { 'chrome://policy' } else { 'edge://policy' }) to check."
}