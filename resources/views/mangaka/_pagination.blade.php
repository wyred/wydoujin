{{-- One-line wrapper so the JSON path can render the pagination component
     (a @props component can't be rendered as a plain view). / JSON応答用ラッパー。 --}}
<x-pagination :paginator="$paginator" />
