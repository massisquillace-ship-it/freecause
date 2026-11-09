<?php

namespace App\Mail;

use App\Models\Petition;
use App\Models\User;
use App\Support\Locale;
use App\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class PetitionNewSignaturesMail extends Mailable implements ShouldQueue
{
    use GatedByMailQuota, Queueable, SerializesModels;

    public bool $transactional = true;

    public function __construct(
        public Petition $petition,
        public User $owner,
        public int $count,
        public Collection $signers
    ) {}

    public function build()
    {
        $locale = Locale::toShort($this->owner->locale ?: default_locale());
        $translation = $this->petition->translationOrFallback($locale, default_locale());
        $token = $this->owner->ensureUnsubscribeToken();

        $petitionUrl = $translation
            ? route('petition.show', [
                'locale' => $translation->locale,
                'slug' => $translation->slug,
                'id' => $this->petition->id,
            ])
            : lroute('home', ['locale' => $locale]);

        return $this->subject(MailText::get('petition_new_signatures_subject', ['count' => $this->count], $locale))
            ->view('emails.petition-new-signatures')
            ->with([
                'emailLocale' => $locale,
                'firstName' => $this->owner->first_name ?: $this->owner->name,
                'petitionTitle' => $translation?->title ?? MailText::get('common_petition', [], $locale),
                'petitionUrl' => $petitionUrl,
                'count' => $this->count,
                'signers' => $this->signers,
                'heading' => MailText::get('petition_new_signatures_heading', ['count' => $this->count], $locale),
                'intro' => MailText::get('petition_new_signatures_intro', ['title' => $translation?->title ?? '', 'count' => $this->count], $locale),
                'guestNote' => MailText::get('petition_new_signatures_guest_note', [], $locale),
                'footer' => MailText::get('petition_new_signatures_footer', [], $locale),
                'unsubscribeLabel' => MailText::get('petition_new_signatures_unsubscribe', [], $locale),
                'manageLabel' => MailText::get('common_manage_preferences', [], $locale),
                'unsubscribeUrl' => lroute('preferences.unsubscribe', ['locale' => $locale, 'token' => $token]),
                'preferencesUrl' => lroute('preferences.show', ['locale' => $locale]),
            ]);
    }
}
