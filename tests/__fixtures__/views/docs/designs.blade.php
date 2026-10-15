@if ($has_designs)
    <fieldset>
        <legend>Design</legend>
        @foreach ($designs as $design)
            <label>
                <input type="radio" name="design" value="{!! $design['handle'] !!}" @checked($design['is_selected'])>
                <img src="{!! $design['preview'] !!}" alt="" width="240" height="240">
                {!! $design['name'] !!}
            </label>
        @endforeach
    </fieldset>
@endif
