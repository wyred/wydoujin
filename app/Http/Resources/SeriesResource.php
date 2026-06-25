<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** JSON shape of a series. / シリーズのAPI表現。 */
class SeriesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_auto' => (bool) $this->is_auto,
            'mangaka_id' => $this->mangaka_id,
            'works_count' => $this->whenCounted('works'),
            'works' => WorkResource::collection($this->whenLoaded('works')),
        ];
    }
}
