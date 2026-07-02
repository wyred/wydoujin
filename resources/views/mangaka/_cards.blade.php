@foreach ($mangaka as $artist)
    <x-collection-card href="/mangaka/{{ $artist->slug }}" :path="$artist->rep_cover" :title="$artist->name" :count="$artist->works_count" />
@endforeach
