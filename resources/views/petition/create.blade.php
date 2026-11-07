@php
    $mode = $mode ?? 'create';
    $isEdit = $mode === 'edit';

    $oldTitle = old('title', $tr->title ?? '');
    $oldDesc = old('description', $tr->description ?? '');

    $oldGoal = old('goal_signatures', $petition->goal_signatures ?? 100);
    $oldCategory = old('category_id', $petition->category_id ?? null);

    $oldTags = old('tags', $petition->tags ?? '');
    $oldImageUrl = old('image_url', $petition->image_url ?? '');
    $oldYoutube = old('youtube', $petition->youtube_url ?? '');
    $oldTarget = old('target', $petition->target ?? '');
    $oldCommunity = old('community', $petition->community ?? '');
    $oldCommunityUrl = old('community_url', $petition->community_url ?? '');
    $oldCity = old('city', $petition->city ?? '');
    $oldCountry = old('country', $petition->country ?? '');
    $countries = \App\Support\Countries::all();
    $citySuggestionsUrl = route('geo.cities', ['locale' => app()->getLocale()]);
    $activeAuthTab = old('auth_form') === 'register' ? 'register' : 'login';

@endphp

@extends('layouts.legacy')

@section('title', $isEdit ? __('create.title_edit') : __('create.title_create'))

@push('head')
    <link href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css" rel="stylesheet" integrity="sha384-cPa8kzsYWhqpAfWOLWYIw3V0BhPi/m3lrd8tBTPxr2NrYCHRVZ7xy1cEoRGOM/03" crossorigin="anonymous">
    <style>@view-transition { navigation: none; }</style>
@endpush

@section('content')
    <section class="fc-section">
        <x-ui.container class="max-md:px-3">

            @auth
                @php
                    $publishOrganizations = \App\Models\Organization::public()->where('owner_user_id', auth()->id())->orderByDesc('verified_at')->orderByDesc('id')->get();
                    $petitionCauses = \App\Models\Cause::orderBy('sort_order')->get();
                @endphp
                @if ($errors->any())
                    <div class="mb-4">
                        <ul class="mb-0 space-y-1 text-sm">
                            @foreach ($errors->keys() as $field)
                                <li><a href="#field-{{ $field === 'description' ? 'petition_description' : str_replace('.', '_', $field) }}" class="underline">{{ $errors->first($field) }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (! $isEdit && ! auth()->user()->hasCompleteProfile())
                    <x-ui.alert variant="warning" class="mb-4">
                        <p class="mb-2">{{ __('profile.complete_required_create') }}</p>
                        <x-ui.button variant="secondary" size="sm" :href="lroute('profile', ['return' => request()->fullUrl()])">{{ __('profile.finish_profile') }}</x-ui.button>
                    </x-ui.alert>
                @endif
            @endauth

            @if(!$isEdit)
                <div class="flex flex-wrap items-center justify-center gap-16 mb-10 max-md:hidden" aria-label="Petition creation progress">
                    <div class="flex items-center gap-4 min-w-[240px]" aria-current="step">
                        <span class="inline-block w-16 h-16 bg-[url('/legacy/images/startpetition-icons.png')] bg-no-repeat bg-[length:200%_300%] bg-[position:100%_0%]"></span>
                        <div class="text-xl font-semibold text-brand whitespace-nowrap">{{ __('petition.step_create') }}</div>
                    </div>

                    <div class="flex items-center gap-4 min-w-[240px]">
                        <span class="inline-block w-16 h-16 opacity-60 bg-[url('/legacy/images/startpetition-icons.png')] bg-no-repeat bg-[length:200%_300%] bg-[position:0%_50%]"></span>
                        <div class="text-xl font-medium text-muted whitespace-nowrap">{{ __('petition.step_share') }}</div>
                    </div>

                    <div class="flex items-center gap-4 min-w-[240px]">
                        <span class="inline-block w-16 h-16 opacity-60 bg-[url('/legacy/images/startpetition-icons.png')] bg-no-repeat bg-[length:200%_300%] bg-[position:0%_100%]"></span>
                        <div class="text-xl font-medium text-muted whitespace-nowrap">{{ __('petition.step_change') }}</div>
                    </div>
                </div>

                @auth
                <nav class="md:hidden" aria-label="Petition creation progress" data-stepper>
                    <span class="w-2 h-2 rounded-full bg-brand" aria-current="step"></span>
                    <span class="w-2 h-2 rounded-full bg-border"></span>
                    <span class="w-2 h-2 rounded-full bg-border"></span>
                    <span class="text-sm text-muted tabular-nums ms-2">1/3</span>
                </nav>
                @endauth

                @guest
                    <div class="mb-4 max-w-[720px] mx-auto [&_.nav-link]:w-full [&_.nav-link]:text-ink [&_.nav-link]:font-semibold max-md:[&_.nav-link]:py-2 max-md:[&_.nav-link]:px-2 max-md:[&_.tab-pane>div]:p-4 max-md:[&_.tab-pane>div]:border-t-0 max-md:[&_.tab-pane>div]:rounded-t-none max-md:[&_.tab-pane>div]:rounded-b-lg max-md:[&_.tab-pane>div]:shadow-none max-md:[&_.form-control]:w-full max-md:[&_.form-select]:w-full max-md:[&_.btn]:w-full">
                        <ul class="nav nav-tabs nav-fill" role="tablist" aria-label="Authentication options">
                            <li class="nav-item" role="presentation">
                                <button
                                    class="nav-link @if($activeAuthTab === 'login') active text-brand @endif"
                                    id="create-login-tab"
                                    data-bs-toggle="tab"
                                    data-bs-target="#create-login-pane"
                                    type="button"
                                    role="tab"
                                    aria-controls="create-login-pane"
                                    aria-selected="{{ $activeAuthTab === 'login' ? 'true' : 'false' }}"
                                >
                                    {{ __('auth.login') }}
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button
                                    class="nav-link @if($activeAuthTab === 'register') active text-brand @endif"
                                    id="create-register-tab"
                                    data-bs-toggle="tab"
                                    data-bs-target="#create-register-pane"
                                    type="button"
                                    role="tab"
                                    aria-controls="create-register-pane"
                                    aria-selected="{{ $activeAuthTab === 'register' ? 'true' : 'false' }}"
                                >
                                    {{ __('auth.register') }}
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content">
                            <div
                                class="tab-pane fade @if($activeAuthTab === 'login') show active @endif"
                                id="create-login-pane"
                                role="tabpanel"
                                aria-labelledby="create-login-tab"
                                tabindex="0"
                            >
                                @include('auth.partials._login_card', ['redirect' => request()->getRequestUri()])
                            </div>
                            <div
                                class="tab-pane fade @if($activeAuthTab === 'register') show active @endif"
                                id="create-register-pane"
                                role="tabpanel"
                                aria-labelledby="create-register-tab"
                                tabindex="0"
                            >
                                @include('auth.partials._register_card', ['redirect' => request()->getRequestUri()])
                            </div>
                        </div>
                    </div>
                @endguest
            @else
                <h1 class="text-2xl tracking-tight mb-6 max-md:mb-4">{{ __('create.heading_edit') }}</h1>
            @endif

            @auth
                <div class="relative flex flex-col min-w-0 bg-white bg-clip-border rounded-md border-0 shadow-elev-1">
                    <div class="grow shrink basis-auto p-6 max-md:p-4">

                        <form data-petition-form data-organization-unavailable="{{ __('organizations.invalid_attribution') }}" data-draft-locale="{{ app()->getLocale() }}" data-draft-user="{{ auth()->id() }}" data-draft-id="{{ $isEdit ? $petition->id : 'new' }}" data-msg-type="{{ __('create.err_file_type') }}" data-msg-size="{{ __('create.err_file_size') }}" data-msg-conflict="{{ __('create.err_image_conflict') }}" data-step-label="{{ __('create.step_of') }}" data-next-label="{{ __('create.next') }}" data-back-label="{{ __('create.back') }}" data-draft-saved="{{ __('create.draft_saved') }}" class="max-md:[&_.row]:[--bs-gutter-y:0.75rem] max-md:[&_.mb-3]:mb-3 max-md:[&_.form-control]:w-full max-md:[&_.form-select]:w-full" method="POST" action="{{ $isEdit
                            ? lroute('petition.update', ['slug' => $tr->slug, 'id' => $petition->id])
                            : lroute('petition.store') }}" enctype="multipart/form-data">
                            @csrf

                            <fieldset class="mb-6 border-2 border-brand rounded-md p-4" data-form-step>
                                <legend class="px-2 font-semibold">{{ __('create.step_story') }}</legend>
                                <div class="mb-3">
                                    <x-ui.input id="field-title" name="title" :label="__('form.title')" :value="$oldTitle" :error="$errors->first('title')" required data-maxcount="120" class="max-md:min-h-12" />
                                </div>

                                <div class="mb-3">
                                    <label class="block mb-2" for="petition_description">{{ __('form.description') }}</label>

                                    <div class="border border-border rounded-sm overflow-hidden">
                                        <div id="fc-quill-toolbar" class="flex items-center gap-1.5 p-1.5 bg-surface-2 border-b border-border" role="toolbar" aria-label="Formatting options">
                                            <button class="ql-bold" type="button" aria-label="Bold"></button>
                                            <button class="ql-italic" type="button" aria-label="Italic"></button>
                                            <button class="ql-underline" type="button" aria-label="Underline"></button>

                                            <span class="w-px h-5 bg-border-strong mx-1.5"></span>

                                            <button class="ql-list" value="bullet" type="button" aria-label="Bullet list"></button>
                                            <button class="ql-list" value="ordered" type="button" aria-label="Numbered list"></button>

                                            <span class="flex-1"></span>
                                        </div>

                                        <div id="petition_editor" role="textbox" aria-multiline="true" tabindex="0" aria-label="{{ __('form.description') }}" class="min-h-[260px] text-sm leading-[1.45] [&_.ql-editor]:min-h-[260px] [&_.ql-editor_ul]:list-disc [&_.ql-editor_ul]:pl-[22px] [&_.ql-editor_ol]:list-decimal [&_.ql-editor_ol]:pl-[22px] [&_.ql-editor_li]:list-item"></div>

                                        <textarea id="petition_description" class="sr-only" name="description" aria-label="{{ __('form.description') }}"
                                            @error('description') aria-invalid="true" aria-describedby="field-description-error" @enderror>{{ $oldDesc }}</textarea>
                                    </div>
                                    @error('description')
                                        <p id="field-description-error" class="text-danger text-sm mt-1">{{ $message }}</p>
                                    @enderror

                                    <div class="block pt-2 px-0.5 text-sm text-muted">
                                        {{ __('create.markup_hint') }}
                                        <a class="text-brand underline" href="{{ lroute('how-to-write-a-petition') }}">{{ __('create.help_link') }}</a>
                                    </div>
                                </div>

                                <div class="row g-3 mb-3">
                                    @unless($isEdit)
                                        <div class="col-md-6">
                                            <label class="block mb-2" for="field-goal_signatures">{{ __('form.goal_signatures') }}</label>

                                            <select id="field-goal_signatures" class="form-select max-md:min-h-12 @error('goal_signatures') is-invalid @enderror" name="goal_signatures" required @error('goal_signatures') aria-invalid="true" aria-describedby="field-goal_signatures-error" @enderror>
                                                <option value="">{{ __('create.select_one') }}</option>

                                                @php
                                                    $goalLabel = fn($n) => number_format($n, 0, '.', "'") . ' ' . __('create.signatures_sfx');
                                                @endphp

                                                @foreach (\App\Models\Petition::GOALS as $g)
                                                    <option value="{{ $g }}" @selected((int) $oldGoal === (int) $g)>
                                                        {{ $goalLabel($g) }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('goal_signatures')
                                                <div id="field-goal_signatures-error" class="invalid-feedback block">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    @endunless

                                    <div class="col-md-6">
                                        <label class="block mb-2" for="field-category_id">{{ __('form.category') }}</label>
                                        <select id="field-category_id" class="form-select max-md:min-h-12 @error('category_id') is-invalid @enderror" name="category_id" required @error('category_id') aria-invalid="true" aria-describedby="field-category_id-error" @enderror>
                                            <option value="">{{ __('create.select_one') }}</option>
                                            @foreach ($categories as $c)
                                                <option value="{{ $c->id }}" @selected((int) $oldCategory === (int) $c->id)>
                                                    {{ $c->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('category_id')
                                            <div id="field-category_id-error" class="invalid-feedback block">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <x-ui.input id="field-tags" name="tags" :label="__('form.tags')" :value="$oldTags" :hint="__('create.tags_hint')" :error="$errors->first('tags')" class="max-md:min-h-12" />
                                </div>
                            </fieldset>

                            <fieldset class="mb-6" data-form-step>
                                <legend class="font-semibold">{{ __('create.step_media') }}</legend>
                                <details open>
                                    <summary class="cursor-pointer text-sm text-brand">{{ __('create.media_toggle') }}</summary>
                                    <div class="mb-3" data-image-field>
                                        <label class="block mb-2" for="petition-image">{{ __('form.image') }}</label>

                                        <p class="text-muted text-sm mb-2" data-image-instructions>
                                            {{ __('create.image_instructions') }}
                                        </p>

                                        <input type="hidden" name="MAX_FILE_SIZE" value="8388608">

                                        <input type="file" id="petition-image" class="form-control max-md:min-h-12 @error('image') is-invalid @enderror"
                                            name="image" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp"
                                            data-image-input data-max-size="8388608" @error('image') aria-invalid="true" aria-describedby="petition-image-error" @enderror>

                                        <small class="text-muted block mt-1" data-image-formats>
                                            {{ __('create.image_formats') }}
                                        </small>

                                        @error('image')
                                            <div id="petition-image-error" class="invalid-feedback block">{{ $message }}</div>
                                        @enderror

                                        <div class="mt-4 hidden" data-image-preview>
                                            <img src="" alt="{{ __('create.image_preview_alt') }}" class="rounded-md border-border shadow-elev-1 max-h-[220px] w-auto h-auto object-contain" data-image-preview-img>
                                            <div class="flex items-center justify-between mt-2">
                                                <small class="text-muted">
                                                    <span data-image-name></span>
                                                    <span class="ms-1" data-image-size></span>
                                                </small>
                                                <button type="button" class="fcui-btn fcui-btn--sm fcui-btn--secondary fcui-tap" data-image-remove>
                                                    {{ __('create.remove') }}
                                                </button>
                                            </div>
                                        </div>

                                        @if($oldImageUrl)
                                            <div class="mt-4">
                                                <img src="{{ $isEdit && $oldImageUrl === $petition->image_url ? $petition->coverUrl() : $oldImageUrl }}" class="rounded-md border-border shadow-elev-1 max-h-[220px]" alt="{{ $oldTitle }}">
                                            </div>
                                        @elseif($isEdit && ($petition->cover_image || $petition->image_url))
                                            <div class="mt-4">
                                                <img src="{{ $petition->coverUrl() }}" class="rounded-md border-border shadow-elev-1 max-h-[220px]" alt="{{ $tr->title }}">
                                                <div class="block min-h-6 pl-6 mb-0.5 mt-2">
                                                    <input class="form-check-input" type="checkbox" name="remove_image" value="1" id="remove-petition-image">
                                                    <label class="form-check-label" for="remove-petition-image">{{ __('create.remove_current_image') }}</label>
                                                </div>
                                            </div>
                                        @endif

                                        <div class="flex items-center text-center gap-3 my-4 text-muted text-xs before:flex-1 before:h-px before:bg-border after:flex-1 after:h-px after:bg-border before:content-[''] after:content-['']" role="separator">
                                            <span>{{ __('create.or') }}</span>
                                        </div>

                                        <div class="mt-2">
                                            <label class="block mb-2" for="field-image_url">{{ __('form.image_external') }}</label>
                                            <input id="field-image_url" class="form-control max-md:min-h-12 @error('image_url') is-invalid @enderror"
                                                name="image_url" value="{{ $oldImageUrl }}" placeholder="https://" data-image-url @error('image_url') aria-invalid="true" aria-describedby="field-image_url-error" @enderror>
                                            @error('image_url')
                                                <div id="field-image_url-error" class="invalid-feedback block">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <x-ui.input id="field-youtube" name="youtube" :label="__('form.youtube')" :value="$oldYoutube" :error="$errors->first('youtube')" class="max-md:min-h-12" />
                                    </div>
                                </details>
                            </fieldset>

                            <fieldset class="mb-6" data-form-step>
                                <legend class="font-semibold">{{ __('create.step_targeting') }}</legend>
                                <div class="mb-3">
                                    <x-ui.input id="field-target" name="target" :label="__('form.target')" :value="$oldTarget" :error="$errors->first('target')" class="max-md:min-h-12" />
                                </div>

                                <div class="mb-3">
                                    <x-ui.input id="field-community" name="community" :label="__('form.community')" :value="$oldCommunity" :error="$errors->first('community')" class="max-md:min-h-12" />
                                </div>

                                <div class="mb-3">
                                    <x-ui.input id="field-community_url" name="community_url" :label="__('form.community_url')" :value="$oldCommunityUrl" :error="$errors->first('community_url')" class="max-md:min-h-12" />
                                </div>

                                <div class="mb-3">
                                    <label class="block mb-1 text-sm font-medium text-ink" for="field-country">{{ __('form.country') }}</label>
                                    <select name="country" id="field-country" class="form-control max-md:min-h-12 w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-brand @error('country') is-invalid @enderror" @error('country') aria-invalid="true" aria-describedby="field-country-error" @enderror>
                                        <option value="">-- {{ __('form.country_select') }} --</option>
                                        @foreach ($countries as $code => $name)
                                            <option value="{{ $code }}" @selected($oldCountry === $code)>{{ $name }}</option>
                                        @endforeach
                                    </select>
                                    @error('country')
                                        <div id="field-country-error" class="invalid-feedback block">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="mb-6">
                                    <label class="block mb-1 text-sm font-medium text-ink" for="field-city">{{ __('form.city') }}</label>
                                    <input type="text" name="city" id="field-city" value="{{ $oldCity }}"
                                        class="form-control max-md:min-h-12 w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-brand @error('city') is-invalid @enderror"
                                        autocomplete="off" list="fc-city-list" placeholder="{{ __('form.city_placeholder') }}" data-city-suggestions="{{ $citySuggestionsUrl }}" data-searching-label="{{ __('form.city_searching') }}" data-suggestions-label="{{ __('form.city_suggestions') }}" @error('city') aria-invalid="true" aria-describedby="field-city-error" @enderror />
                                    <datalist id="fc-city-list"></datalist>
                                    <p class="mt-1 text-xs text-muted" aria-live="polite" data-city-status></p>
                                    @error('city')
                                        <div id="field-city-error" class="invalid-feedback block">{{ $message }}</div>
                                    @enderror
                                </div>
                                @if(!$isEdit && $publishOrganizations->isNotEmpty())
                                    <label class="block mb-4" for="field-organization_id">{{ __('organizations.publish_as') }}
                                        <select class="form-control block w-full" id="field-organization_id" name="organization_id" aria-label="{{ __('organizations.publish_as') }}">
                                            <option value="" @selected((string)old('organization_id', $publishOrganizations->first()->id) === '')>{{ __('organizations.myself') }}</option>
                                            @foreach($publishOrganizations as $publishOrg)<option value="{{ $publishOrg->id }}" @selected((string)old('organization_id', $publishOrganizations->first()->id) === (string)$publishOrg->id)>{{ $publishOrg->name }}</option>@endforeach
                                        </select>
                                    </label>
                                @endif
                                <details id="field-causes" class="mb-4" @if($errors->has('causes') || $errors->has('causes.*')) open @endif><summary>{{ __('organizations.petition_causes') }}</summary><fieldset class="mt-3"><legend class="sr-only">{{ __('organizations.petition_causes') }}</legend>
                                    @foreach($petitionCauses as $cause)<label class="inline-flex items-center gap-2 mr-4 mb-2"><input type="checkbox" name="causes[]" value="{{ $cause->id }}" @checked(in_array($cause->id, old('causes', isset($petition) ? $petition->causes->pluck('id')->all() : [])))>{{ $cause->label() }}</label>@endforeach
                                    <input type="hidden" name="causes_present" value="1">
                                </fieldset></details>
                            </fieldset>

                            <div class="text-center max-md:[&_.fcui-btn]:w-full">
                                <span data-autosave-status role="status" class="text-sm text-muted me-3">{{ __('create.draft_auto') }}</span>
                                <x-ui.button type="submit" variant="primary" class="max-md:hidden max-md:min-h-12"
                                    :loadingText="$isEdit ? __('create.update') : __('create.submitting')">
                                    {{ $isEdit ? __('create.update') : __('form.submit') }}
                                </x-ui.button>
                            </div>

                            <x-ui.sticky-action class="md:hidden" :label="$isEdit ? __('create.update') : __('form.submit')" />
                        </form>

                    </div>
                </div>
            @endauth

        </x-ui.container>

        <x-ads.rotate placement="inline" class="mt-6 text-center" />
        <x-ads.rotate placement="page_mid" class="mt-6" />
    </section>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js" integrity="sha384-QUJ+ckWz1M+a7w0UfG1sEn4pPrbQwSxGm/1TIPyioqXBrwuT9l4f9gdHWLDLbVWI" crossorigin="anonymous"></script>
    <script src="{{ asset('legacy/js-v2/petition-create.js') }}?v={{ filemtime(public_path('legacy/js-v2/petition-create.js')) }}"></script>
    <script src="{{ asset('legacy/js-v2/petition-autosave.js') }}?v={{ filemtime(public_path('legacy/js-v2/petition-autosave.js')) }}"></script>
@endpush

