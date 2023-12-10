<div class="col-12 col-md-4 col-lg-3">
    <label for="{{ $key }}" class="form-label text-truncate"> {{ $label }} </label>

    @if ($type == DataType::BOOLEAN)
        <select wire:model.live="value" class="form-select" @if (!$writeable) disabled @endif>
            <option value="1" @if ($value)  selected @endif> Yes </option>
            <option value="0" @if (!$value) selected @endif> No  </option>
        </select>
    @elseif ($type == DataType::ENUM)
        <select wire:model.live="value" class="form-select" @if (!$writeable) disabled @endif>
            @foreach ($enum::toSelectArray() as $index => $label)
                <option
                    value="{{ $index }}"
                    @if ($label == $value)
                        selected
                    @endif
                > {{ $label }} </option>
            @endforeach
        </select>
    @else
        <input
            wire:model.live="value"
            id="{{ $key }}"
            type="{{
                $type == DataType::INTEGER
                ||
                $type == DataType::FLOAT
                    ? 'number'
                    : 'text'
            }}"
            class="form-control"
            value="{{ $value }}"
            @if (!$writeable) disabled @endif
        >
    @endif

    <div class="form-text"> {!! $hint !!} </div>

    @if ($error)
        <span class="text-danger mb-3 text-center text-danger">
            {{ $error }}
        </span>
    @endif
</div>