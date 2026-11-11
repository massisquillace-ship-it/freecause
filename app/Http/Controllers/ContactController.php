<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactRequest;
use App\Mail\ContactMail;
use App\Support\AppLog;
use App\Support\Settings;
use App\Support\Spam;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function send(ContactRequest $request)
    {
        if (! Settings::get('email_contact_enabled', true, 'global')) {
            abort(404);
        }

        $data = $request->validated();

        // Spam defenses, consistent with the sign/register flows: CAPTCHA
        // (no-op when Turnstile is disabled by the admin), keyword filter,
        // and per-IP route throttle. The contact form previously had none,
        // leaving it an unauthenticated mail-relay / spam vector.
        if (! Spam::verifyTurnstile($request->input('cf-turnstile-response'))) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('sign.captcha_failed')], 422);
            }

            toast(__('sign.captcha_failed'), 'error');

            return back()->withInput();
        }

        $spamText = implode(' ', [$data['name'], $data['text']]);

        if (Spam::isSpam($spamText)) {
            Spam::log('contact', json_encode($data));

            if ($request->expectsJson()) {
                return response()->json(['message' => __('sign.suspicious')], 422);
            }

            toast(__('sign.suspicious'), 'error');

            return back()->withInput();
        }

        $capKey = 'mail:contact:'.today()->toDateString();
        Cache::add($capKey, 0, now()->endOfDay());

        if (Cache::increment($capKey) > 30) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('contacts.failed')], 500);
            }

            toast(__('contacts.failed'), 'error');

            return back()
                ->withInput()
                ->withErrors(['email' => __('contacts.failed')]);
        }

        $adminEmail = Settings::get('email_to_staff', '', 'global') ?: config('mail.from.address');
        $subject = Settings::get('email_contact_subject', '', 'global') ?: null;

        try {
            Mail::to($adminEmail)->queue(new ContactMail($data['name'], $data['email'], $data['text'], $subject));
        } catch (\Exception $e) {
            AppLog::error('Contact form mail failed', $e->getMessage(), 'contacts');

            if ($request->expectsJson()) {
                return response()->json(['message' => __('contacts.failed')], 500);
            }

            toast(__('contacts.failed'), 'error');

            return back()
                ->withInput()
                ->withErrors(['email' => __('contacts.failed')]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('contacts.sent'),
            ]);
        }

        toast(__('contacts.sent'), 'success');

        return back()->with('contact_sent', true);
    }
}
