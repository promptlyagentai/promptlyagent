@props(['action' => null, 'provider', 'configSchema'])

@php
    $isEdit = $action !== null;
@endphp

{{-- Action Name --}}
<div>
    <flux:input
        name="name"
        label="Action Name"
        placeholder="My {{ $provider->getActionTypeName() }}"
        value="{{ old('name', $action?->name) }}"
        required />
    @error('name')
        <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
    @enderror
</div>

{{-- Description --}}
<div>
    <flux:textarea
        name="description"
        label="Description (Optional)"
        placeholder="Describe what this action does..."
        rows="3">{{ old('description', $action?->description) }}</flux:textarea>
    @error('description')
        <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
    @enderror
</div>

{{-- Trigger Condition --}}
<div>
    <flux:select name="trigger_on" label="When to Execute" required>
        <option value="success" {{ old('trigger_on', $action?->trigger_on ?? 'success') === 'success' ? 'selected' : '' }}>
            On Success - Execute only when agent completes successfully
        </option>
        <option value="failure" {{ old('trigger_on', $action?->trigger_on) === 'failure' ? 'selected' : '' }}>
            On Failure - Execute only when agent fails
        </option>
        <option value="always" {{ old('trigger_on', $action?->trigger_on) === 'always' ? 'selected' : '' }}>
            Always - Execute regardless of outcome
        </option>
    </flux:select>
    @error('trigger_on')
        <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
    @enderror
</div>

{{-- Provider-Specific Configuration --}}
@foreach($configSchema as $field => $definition)
    @php
        $fieldType = $definition['type'];
        $fieldName = "config[{$field}]";
        $fieldValue = old('config.' . $field, $action?->config[$field] ?? $definition['default'] ?? '');
        $isRequired = isset($definition['required']) && $definition['required'];
        // Issue #178: Hide blocks_template when message_format is not "blocks"
        $isBlocksTemplate = ($field === 'blocks_template');
        $messageFormatValue = old('config.message_format', $action?->config['message_format'] ?? 'blocks');
    @endphp

    @if($isBlocksTemplate)
        <div x-data="{ messageFormat: '{{ $messageFormatValue }}' }"
             :class="{ 'hidden': messageFormat !== 'blocks' }"
             x-init="console.log('[blocks_template] Init:', messageFormat, 'visible:', messageFormat === 'blocks')"
             @format-changed.window="console.log('[blocks_template] Format changed:', $event.detail); messageFormat = $event.detail">
    @else
        <div>
    @endif
        <flux:label>{{ $definition['label'] }}</flux:label>

        @if($fieldType === 'select' && $isRequired)
            @if($field === 'message_format')
                <flux:select name="{{ $fieldName }}" required
                    x-on:change="window.dispatchEvent(new CustomEvent('format-changed', { detail: $event.target.value })); console.log('Dispatching format-changed:', $event.target.value)">
                    @foreach($definition['options'] as $value => $label)
                        <option value="{{ $value }}" {!! $fieldValue == $value ? 'selected' : '' !!}>
                            {{ $label }}
                        </option>
                    @endforeach
                </flux:select>
            @else
                <flux:select name="{{ $fieldName }}" required>
                    @foreach($definition['options'] as $value => $label)
                        <option value="{{ $value }}" {!! $fieldValue == $value ? 'selected' : '' !!}>
                            {{ $label }}
                        </option>
                    @endforeach
                </flux:select>
            @endif
        @endif

        @if($fieldType === 'select' && !$isRequired)
            @if($field === 'message_format')
                <flux:select name="{{ $fieldName }}"
                    x-on:change="window.dispatchEvent(new CustomEvent('format-changed', { detail: $event.target.value })); console.log('Dispatching format-changed:', $event.target.value)">
                    @foreach($definition['options'] as $value => $label)
                        <option value="{{ $value }}" {!! $fieldValue == $value ? 'selected' : '' !!}>
                            {{ $label }}
                        </option>
                    @endforeach
                </flux:select>
            @else
                <flux:select name="{{ $fieldName }}">
                    @foreach($definition['options'] as $value => $label)
                        <option value="{{ $value }}" {!! $fieldValue == $value ? 'selected' : '' !!}>
                            {{ $label }}
                        </option>
                    @endforeach
                </flux:select>
            @endif
        @endif

        @if($fieldType === 'number' && $isRequired)
            <flux:input
                type="number"
                name="{{ $fieldName }}"
                value="{{ $fieldValue }}"
                placeholder="{{ $definition['placeholder'] ?? '' }}"
                min="{{ $definition['min'] ?? '' }}"
                max="{{ $definition['max'] ?? '' }}"
                required />
        @endif

        @if($fieldType === 'number' && !$isRequired)
            <flux:input
                type="number"
                name="{{ $fieldName }}"
                value="{{ $fieldValue }}"
                placeholder="{{ $definition['placeholder'] ?? '' }}"
                min="{{ $definition['min'] ?? '' }}"
                max="{{ $definition['max'] ?? '' }}" />
        @endif

        @if($fieldType === 'json')
            <flux:textarea
                name="{{ $fieldName }}"
                placeholder="{{ $definition['placeholder'] ?? '' }}"
                rows="6">{{ is_array($fieldValue) ? json_encode($fieldValue, JSON_PRETTY_PRINT) : $fieldValue }}</flux:textarea>
        @endif

        @if($fieldType === 'password' && $isRequired)
            <flux:input
                type="password"
                name="{{ $fieldName }}"
                value="{{ $fieldValue }}"
                maxlength="1000"
                placeholder="{{ $definition['placeholder'] ?? '' }}"
                required />
        @endif

        @if($fieldType === 'password' && !$isRequired)
            <flux:input
                type="password"
                name="{{ $fieldName }}"
                value="{{ $fieldValue }}"
                maxlength="1000"
                placeholder="{{ $definition['placeholder'] ?? '' }}" />
        @endif

        @if($fieldType === 'text' && $isRequired)
            <flux:input
                name="{{ $fieldName }}"
                value="{{ $fieldValue }}"
                placeholder="{{ $definition['placeholder'] ?? '' }}"
                required />
        @endif

        @if($fieldType === 'text' && !$isRequired)
            <flux:input
                name="{{ $fieldName }}"
                value="{{ $fieldValue }}"
                placeholder="{{ $definition['placeholder'] ?? '' }}" />
        @endif

        @if($fieldType === 'textarea' && $isRequired)
            <flux:textarea
                name="{{ $fieldName }}"
                placeholder="{{ $definition['placeholder'] ?? '' }}"
                rows="{{ $definition['rows'] ?? 4 }}"
                required>{{ $fieldValue }}</flux:textarea>
        @endif

        @if($fieldType === 'textarea' && !$isRequired)
            <flux:textarea
                name="{{ $fieldName }}"
                placeholder="{{ $definition['placeholder'] ?? '' }}"
                rows="{{ $definition['rows'] ?? 4 }}">{{ $fieldValue }}</flux:textarea>
        @endif

        @if($fieldType === 'checkbox')
            @php
                // Determine if checkbox should be checked
                // Priority: old input > saved value > default value
                $isChecked = false;
                if (old('config.' . $field) !== null) {
                    // Old input exists (validation failed)
                    $isChecked = old('config.' . $field) == '1' || old('config.' . $field) === true;
                } elseif (isset($action->config[$field])) {
                    // Saved value exists
                    $isChecked = $action->config[$field] == '1' || $action->config[$field] === true || $action->config[$field] === 'true';
                } elseif (isset($definition['default'])) {
                    // Use default value for new actions
                    $isChecked = $definition['default'] === true || $definition['default'] === '1';
                }
            @endphp
            <div class="flex items-center">
                {{-- Hidden field to ensure false value is submitted when checkbox is unchecked --}}
                <input type="hidden" name="{{ $fieldName }}" value="0" />
                <input
                    type="checkbox"
                    name="{{ $fieldName }}"
                    id="{{ $fieldName }}"
                    value="1"
                    {{ $isChecked ? 'checked' : '' }}
                    class="h-4 w-4 rounded border-default bg-surface text-accent focus:ring-accent focus:ring-offset-surface" />
                <label for="{{ $fieldName }}" class="ml-2 text-sm text-primary">
                    {{ $definition['label'] }}
                </label>
            </div>
        @endif

        @if($fieldType === 'integration_select')
            {{--
                Integration select fields should be handled by provider-specific form sections
                (e.g., slack-integration::components.integration-token-field)
                Skip rendering to avoid duplicates and PHP errors
            --}}
        @endif

        @if(isset($definition['help']))
            <flux:text size="xs" class="mt-1 text-secondary">
                {!! $definition['help'] !!}
            </flux:text>
        @endif

        @if($errors->has('config.' . $field))
            <flux:text variant="danger" class="mt-1">{{ $errors->first('config.' . $field) }}</flux:text>
        @endif
    </div>
@endforeach
