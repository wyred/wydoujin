<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** JSON shape of a mangaka (top-level folder). / マンガ家のAPI表現。 */
class MangakaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'works_count' => $this->whenCounted('works'),
            'series_count' => $this->whenCounted('series'),
            'series' => SeriesResource::collection($this->whenLoaded('series')),
            'works' => WorkResource::collection($this->whenLoaded('works')),
        ];
    }
}
