<?php

namespace App\Mail;

use App\Models\Organization;
use App\Support\MailText;
use Illuminate\Mail\Mailable;

class OrganizationStatusMail extends Mailable
{
    public function __construct(public string $event, public array $notice, public string $emailLocale, public bool $toAdmin, public int $organizationId)
    {
        $this->locale($emailLocale);
    }

    public function build(): static
    {
        $kind = $this->toAdmin ? 'admin' : $this->event;
        $replace = ['name' => $this->notice['name']];
        $subject = MailText::get('organization_'.$kind.'_subject', $replace, $this->emailLocale);
        $body = MailText::get('organization_'.$kind.'_body', $replace, $this->emailLocale);
        $profile = in_array($this->event, ['verify', 'restore'], true) && ($this->notice['restored_verified'] ?? false);
        $path = $this->toAdmin ? 'admin/organizations/'.$this->organizationId : $this->emailLocale.'/org/'.rawurlencode($this->notice['slug']).($profile ? '' : '/manage');

        if (! $this->toAdmin && $this->event === 'reject' && ! empty($this->notice['rejected_claimant'])) {
            $path = $this->emailLocale.'/org/'.rawurlencode($this->notice['slug']).'/claim';
        }

        return $this->subject($subject)->view('emails.organization-status')->with([
            'heading' => $subject, 'bodyText' => $body,
            'reason' => $this->notice['reason'] ?? '', 'destination' => canonical_url($path),
            'actionText' => MailText::get('organization_action', [], $this->emailLocale),
            'supportEmail' => Organization::supportEmail(),
        ]);
    }
}
