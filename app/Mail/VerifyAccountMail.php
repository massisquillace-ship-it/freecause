<?php

namespace App\Mail;

use App\Support\Locale;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VerifyAccountMail extends Mailable implements ShouldQueue
{
    use GatedByMailQuota, Queueable, SerializesModels;

    public string $firstName;

    public string $verificationToken;

    public $locale;

    public bool $isEmailChange;

    public function __construct($user, string $locale, public array $organizationConsentLinks = [])
    {
        $this->firstName = $user->first_name ?? '';
        $this->verificationToken = $user->verification_token ?? '';
        $this->locale = Locale::toShort($user->locale ?: $locale);
        $this->isEmailChange = ! empty($user->pending_email);
    }

    public function build()
    {
        $subject = Settings::get('email_verify_subject', '', 'global') ?: trans('emails.verify_account.subject', [], $this->locale);
        $greeting = Settings::get('email_verify_greeting', '', 'global') ?: trans('emails.verify_account.greeting', [], $this->locale);
        $buttonText = Settings::get('email_verify_button_text', '', 'global') ?: trans('emails.verify_account.button', [], $this->locale);
        $introKey = $this->isEmailChange ? 'emails.verify_account.intro_change' : 'emails.verify_account.intro';

        return $this->subject($subject)
            ->view('emails.verify-account')
            ->with([
                'organizationConsentLinks' => $this->organizationConsentLinks,
                'locale' => $this->locale,
                'emailLocale' => $this->locale,
                'token' => $this->verificationToken,
                'preheader' => trans('emails.verify_account.preheader', [], $this->locale),
                'eyebrow' => trans('emails.verify_account.eyebrow', [], $this->locale),
                'greeting' => $greeting,
                'hello' => trans('emails.verify_account.hello', ['name' => $this->firstName], $this->locale),
                'intro' => trans($introKey, [], $this->locale),
                'hint' => trans('emails.verify_account.hint', [], $this->locale),
                'buttonText' => $buttonText,
                'noteExpiry' => trans('emails.verify_account.note_expiry', [], $this->locale),
                'noteLinkHelp' => trans('emails.verify_account.note_link_help', [], $this->locale),
                'noteIgnore' => trans('emails.verify_account.note_ignore', [], $this->locale),
            ]);
    }
}
