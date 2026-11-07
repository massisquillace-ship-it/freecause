<?php

namespace App\Http\Middleware;

use App\Support\AppLog;
use Closure;
use Illuminate\Http\Request;

class AdminAuditLog
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        try {
            $user = admin_user();
            $adminId = $user?->id ?? 'unknown';
            $adminEmail = $user?->email ?? 'unknown';

            $path = $request->path();
            $method = $request->method();

            $input = $this->redactSensitiveInput($request->all());
            array_walk($input, function (&$v) {
                if (is_string($v) && mb_strlen($v) > 300) {
                    $v = mb_substr($v, 0, 300).'…';
                }
            });

            $status = $response->getStatusCode();

            AppLog::info(
                "Admin action: {$method} /{$path}",
                "Admin #{$adminId} ({$adminEmail}) | Status: {$status} | Input: ".json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'admin.audit'
            );
        } catch (\Throwable) {
        }

        return $response;
    }

    private function redactSensitiveInput(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $input[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $input[$key] = $this->redactSensitiveInput($value);
            }
        }

        return $input;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $key));

        return preg_match('/password|passwd|passphrase|secret|token|authorization|credential|apikey|privatekey|otp|onetimecode|codeverifier|authcode|clientassertion/', $normalized) === 1
            || in_array($normalized, ['verificationreference', 'verificationnote', 'rejectionreason', 'suspensionreason', 'reason'], true)
            || $normalized === 'pass'
            || preg_match('/(?:^|[_-])pass(?:$|[_-])/i', $key) === 1
            || preg_match('/[a-z]Pass$/', $key) === 1;
    }
}
