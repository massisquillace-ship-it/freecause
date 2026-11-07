<svg xmlns="http://www.w3.org/2000/svg" width="320" height="80" viewBox="0 0 320 80" role="img" aria-labelledby="title">
    <title id="title">{{ $org ? $org->name.' — '.__('organizations.badge_campaigns') : __('organizations.profile_unavailable') }}</title>
    <rect width="320" height="80" rx="10" fill="#fffaf7"/>
    <rect x="1" y="1" width="318" height="78" rx="9" fill="none" stroke="#d8cec7"/>
    @if($org)
        <text x="14" y="23" font-family="ui-monospace, monospace" font-size="14" font-weight="700" fill="#25211e">{{ mb_strimwidth($org->name, 0, 30, '…') }}</text>
        <text x="14" y="45" font-family="system-ui, sans-serif" font-size="12" fill="#5b524c">{{ __('organizations.badge_campaigns') }}</text>
        <text x="14" y="65" font-family="system-ui, sans-serif" font-size="12" fill="#25211e">{{ __('organizations.badge_signatures', ['count'=>(int)$org->signatures_total]) }}</text>
    @else
        <text x="14" y="44" font-family="system-ui, sans-serif" font-size="14" fill="#25211e">{{ __('organizations.profile_unavailable') }}</text>
    @endif
</svg>
