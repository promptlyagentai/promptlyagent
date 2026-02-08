{{--
    Auth Header Component

    Purpose: Standard header for authentication pages

    Features:
    - Centered title and description
    - Flux UI heading components
    - Consistent styling across auth pages

    Component Props:
    - @props string $title Main heading text
    - @props string $description Subheading text

    Usage:
    <x-auth-header
        :title="__('Log in to your account')"
        :description="__('Enter your email and password below')"
    />

    Styling:
    - Full width flex column
    - Text center alignment
    - XL heading size
    - Subheading style from Flux

    Used By:
    - login.blade.php
    - register.blade.php
    - forgot-password.blade.php
    - reset-password.blade.php
    - confirm-password.blade.php
--}}
@props([
    'title',
    'description',
])

<div class="flex w-full flex-col text-center">
    <flux:heading size="xl">{{ $title }}</flux:heading>
    <flux:subheading>{{ $description }}</flux:subheading>
</div>
