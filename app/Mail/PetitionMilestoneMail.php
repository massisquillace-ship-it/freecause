<?php

namespace App\Mail;

use App\Models\Petition;
use App\Models\User;
use App\Support\Locale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PetitionMilestoneMail extends Mailable implements ShouldQueue
{
    use GatedByMailQuota, Queueable, SerializesModels, WithListUnsubscribe;

    public bool $transactional = true;

    public function __construct(
        public Petition $petition,
        public User $owner,
        public int $milestone
    ) {}

    public function build()
    {
        $locale = Locale::toShort($this->owner->locale);
        $translation = $this->petition->translationOrFallback($locale, default_locale());

        $petitionUrl = $translation
            ? route('petition.show', [
                'locale' => $translation->locale,
                'slug' => $translation->slug,
                'id' => $this->petition->id,
            ])
            : lroute('home', ['locale' => $locale]);

        $token = $this->owner->ensureUnsubscribeToken();
        $unsubscribeUrl = lroute('preferences.unsubscribe', ['locale' => $locale, 'token' => $token]);
        $firstName = $this->owner->first_name ?: $this->owner->name;
        $petitionTitle = $translation?->title ?? 'your petition';
        $count = number_format($this->milestone);

        return $this->subject(trans('emails.petition_milestone.subject', ['count' => $count], $locale))
            ->view('emails.petition-milestone')
            ->with([
                'emailLocale' => $locale,
                'preheader' => trans('emails.petition_milestone.preheader', [], $locale),
                'eyebrow' => trans('emails.petition_milestone.eyebrow', [], $locale),
                'heading' => trans('emails.petition_milestone.heading', [], $locale),
                'title' => trans('emails.petition_milestone.title', ['count' => $count], $locale),
                'hello' => trans('emails.petition_milestone.hello', ['name' => $firstName], $locale),
                'intro' => trans('emails.petition_milestone.intro', [
                    'title' => '<strong>'.e($petitionTitle).'</strong>',
                    'count' => '<strong>'.e($count).'</strong>',
                ], $locale),
                'share' => trans('emails.petition_milestone.share', [], $locale),
                'action' => trans('emails.petition_milestone.action', [], $locale),
                'petitionUrl' => $petitionUrl,
                'footer' => trans('emails.petition_milestone.footer', [], $locale),
                'unsubscribeLabel' => trans('emails.petition_milestone.unsubscribe', [], $locale),
                'manageLabel' => trans('preferences.manage_preferences', [], $locale),
                'unsubscribeUrl' => $unsubscribeUrl,
                'preferencesUrl' => lroute('preferences.show', ['locale' => $locale]),
            ])
            ->listUnsubscribe($unsubscribeUrl);
    }
}
