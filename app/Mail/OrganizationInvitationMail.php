<?php

namespace App\Mail;

use App\Models\Organization;
use App\Support\MailText;
use Illuminate\Mail\Mailable;

class OrganizationInvitationMail extends Mailable
{
    public function __construct(public string $organizationName, public string $role, public string $acceptUrl, public string $emailLocale)
    {
        $this->locale($emailLocale);
    }

    public function build(): static
    {
        $replace = ['name' => $this->organizationName, 'role' => __('organization_members.'.$this->role, [], $this->emailLocale)];
        $subject = MailText::get('organization_members_subject', $replace, $this->emailLocale);

        return $this->subject($subject)->view('emails.organization-status')->with([
            'heading' => $subject, 'bodyText' => MailText::get('organization_members_body', $replace, $this->emailLocale),
            'reason' => '', 'destination' => $this->acceptUrl,
            'actionText' => MailText::get('organization_members_action', [], $this->emailLocale), 'supportEmail' => Organization::supportEmail(),
        ]);
    }
}
