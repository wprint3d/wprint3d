<div class="col-12 col-md-4 col-lg-3">
    <label for="{{ $key }}" class="form-label text-truncate"> {{ $label }} </label>

    @if ($type == DataType::BOOLEAN)
        <select wire:model="value" class="form-select">
            <option value="1" @if ($value)  selected @endif> Yes </option>
            <option value="0" @if (!$value) selected @endif> No  </option>
        </select>
    @else
        <input
            wire:model="value"
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
        >
    @endif

    <div class="form-text"> {!! $hint !!} </div>

    @if ($error)
        <span class="text-danger mb-3 text-center text-danger">
            {{ $error }}
        </span>
    @endif
</div>