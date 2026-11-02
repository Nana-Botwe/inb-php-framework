@extends('layouts.app')

@section('title', 'Profile')

@section('content')
    <h1 class="h3 mb-4">Profile</h1>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">Profile information</h2>
                    <form action="{{ route('profile.update') }}" method="post" novalidate>
                        @csrf
                        @method('PATCH')
                        <div class="mb-3">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" maxlength="100"
                                   @class(['form-control', 'is-invalid' => error('name')]) required>
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" maxlength="255"
                                   @class(['form-control', 'is-invalid' => error('email')]) required>
                            <div class="form-text">Changing it requires your current password and confirming the new address.</div>
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label for="email_current_password" class="form-label">Current password <span class="text-body-secondary small">(only needed to change your email)</span></label>
                            <input type="password" id="email_current_password" name="email_current_password" autocomplete="current-password"
                                   @class(['form-control', 'is-invalid' => error('email_current_password')])>
                            @error('email_current_password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">Profile photo</h2>
                    <div class="d-flex align-items-center gap-3 mb-3">
                        @if ($user->avatarUrl())
                            <img src="{{ $user->avatarUrl() }}" alt="Your photo" width="72" height="72" class="rounded-circle object-fit-cover">
                        @else
                            <span class="avatar-initials avatar-lg">{{ $user->initials() }}</span>
                        @endif
                        <p class="small text-body-secondary mb-0">JPG, PNG, GIF or WebP up to 5 MB. Photos are resized, converted to WebP and stripped of location data automatically.</p>
                    </div>
                    <form action="{{ route('profile.avatar') }}" method="post" enctype="multipart/form-data">
                        @csrf
                        <div class="input-group">
                            <input type="file" name="avatar" accept="image/*" @class(['form-control', 'is-invalid' => error('avatar')]) required>
                            <button class="btn btn-outline-primary" type="submit">Upload</button>
                            @error('avatar') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">Change password</h2>
                    <form action="{{ route('profile.password') }}" method="post" novalidate>
                        @csrf
                        @method('PUT')
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current password</label>
                            <input type="password" id="current_password" name="current_password" autocomplete="current-password"
                                   @class(['form-control', 'is-invalid' => error('current_password')]) required>
                            @error('current_password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New password</label>
                            <input type="password" id="new_password" name="password" autocomplete="new-password" maxlength="72"
                                   @class(['form-control', 'is-invalid' => error('password')]) required>
                            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label for="password_confirmation" class="form-label">Confirm new password</label>
                            <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" maxlength="72" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Update password</button>
                        <div class="form-text mt-2">Other devices are signed out automatically when you change your password.</div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4" id="two-factor">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2">Two-factor authentication</h2>
                    @if ($user->hasTwoFactorEnabled())
                        <p class="small mb-3"><span class="badge text-bg-success">On</span> Signing in needs your password and a code from your authenticator app.</p>
                    @elseif ($user->getAttributes()['two_factor_secret'] ?? null)
                        <p class="small text-body-secondary">Add this account to your authenticator app (Google Authenticator, Microsoft Authenticator, Authy…) using the key below, then enter the 6-digit code it shows.</p>
                        <div class="p-3 rounded bg-body-tertiary mb-3">
                            <div class="small text-body-secondary">Setup key</div>
                            <code class="fs-6 user-select-all">{{ trim(chunk_split((string) $user->two_factor_secret, 4, ' ')) }}</code>
                            <div class="small mt-2"><a href="{{ $user->twoFactorQrUri() }}">Open in an authenticator app on this device</a></div>
                        </div>
                        <form action="{{ route('two-factor.confirm') }}" method="post" class="d-flex gap-2" novalidate>
                            @csrf
                            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456"
                                   @class(['form-control', 'is-invalid' => error('code')]) required>
                            <button type="submit" class="btn btn-primary">Confirm</button>
                        </form>
                        @error('code') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    @else
                        <p class="small text-body-secondary">Protect your account with a code from your phone in addition to your password.
                            @if ($user->requiresTwoFactor()) <strong>Required for your role.</strong> @endif</p>
                    @endif

                    @session('two_factor_recovery_codes')
                        <div class="alert alert-warning small mt-3">
                            <strong>Save these recovery codes</strong> somewhere safe. Each works once if you lose your phone. They will not be shown again.
                            <ul class="mb-0 mt-2 font-monospace">
                                @foreach ((array) $value as $recoveryCode) <li>{{ $recoveryCode }}</li> @endforeach
                            </ul>
                        </div>
                    @endsession

                    <div class="d-flex flex-wrap gap-2 mt-3">
                        @if (!($user->getAttributes()['two_factor_secret'] ?? null))
                            <form action="{{ route('two-factor.enable') }}" method="post">@csrf <button class="btn btn-outline-primary btn-sm">Turn on two-factor</button></form>
                        @endif
                        @if ($user->hasTwoFactorEnabled())
                            <form action="{{ route('two-factor.recovery-codes') }}" method="post">@csrf <button class="btn btn-outline-secondary btn-sm">New recovery codes</button></form>
                            @unless ($user->requiresTwoFactor())
                                <form action="{{ route('two-factor.disable') }}" method="post">@csrf @method('DELETE') <button class="btn btn-outline-danger btn-sm">Turn off</button></form>
                            @endunless
                        @endif
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-2">Devices</h2>
                    <p class="small text-body-secondary">Sign out everywhere else — other browsers, "remember me" devices and API tokens stop working.</p>
                    <form action="{{ route('profile.logout-others') }}" method="post">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary btn-sm">Sign out of all other devices</button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-danger-subtle">
                <div class="card-body p-4">
                    <h2 class="h5 text-danger mb-2">Delete account</h2>
                    <p class="small text-body-secondary">This permanently deletes your account and data.</p>
                    <form action="{{ route('profile.destroy') }}" method="post" novalidate>
                        @csrf
                        @method('DELETE')
                        <div class="input-group">
                            <input type="password" name="password_delete" placeholder="Confirm with your password" autocomplete="current-password"
                                   @class(['form-control', 'is-invalid' => error('password_delete')]) required>
                            <button type="submit" class="btn btn-danger">Delete</button>
                            @error('password_delete') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
