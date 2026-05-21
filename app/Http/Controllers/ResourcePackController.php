<?php

namespace App\Http\Controllers;

use App\Models\ResourcePack;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ResourcePackController extends Controller
{
    public function index(): JsonResponse
    {
        $defaultPack = $this->ensureDefaultPack();

        /** @var User $user */
        $user = auth()->user();

        $packs = ResourcePack::query()
            ->where(function ($query) use ($user) {
                $query->where('is_public', true)
                    ->orWhere('user_id', $user->id);
            })
            ->orderByDesc('is_default')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (ResourcePack $pack) => $this->serializePack($pack, $user))
            ->values();

        return response()->json($packs);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'data' => ['required', 'array'],
        ]);

        /** @var User $user */
        $user = auth()->user();

        $pack = ResourcePack::query()->create([
            'user_id' => $user->id,
            'public_id' => Str::uuid()->toString(),
            'name' => trim($data['name']),
            'is_public' => false,
            'is_default' => false,
            'data' => $data['data'],
        ]);

        return response()->json($this->serializePack($pack, $user), 201);
    }

    public function update(Request $request, ResourcePack $resourcePack): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'data' => ['required', 'array'],
        ]);

        $this->assertEditable($request, $resourcePack);

        $resourcePack->update([
            'name' => trim($data['name']),
            'data' => $data['data'],
        ]);

        /** @var User $user */
        $user = $request->user();

        return response()->json($this->serializePack($resourcePack, $user));
    }

    public function destroy(Request $request, ResourcePack $resourcePack): JsonResponse
    {
        $this->assertEditable($request, $resourcePack);
        $resourcePack->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    public function show(string $publicId): JsonResponse
    {
        $this->ensureDefaultPack();

        $pack = ResourcePack::query()
            ->where('public_id', $publicId)
            ->first();

        if (!$pack) {
            return response()->json([
                'message' => 'resource_pack_not_found',
            ], 404);
        }

        return response()->json($pack->data);
    }

    public function getDefaultPack(): ResourcePack
    {
        return $this->ensureDefaultPack();
    }

    private function serializePack(ResourcePack $pack, User $user): array
    {
        return [
            'id' => $pack->id,
            'public_id' => $pack->public_id,
            'public_url' => url('/api/resource-pack/' . $pack->public_id),
            'name' => $pack->name,
            'data' => $pack->data,
            'is_public' => (bool) $pack->is_public,
            'is_default' => (bool) $pack->is_default,
            'is_editable' => $pack->user_id === $user->id && !$pack->is_public,
            'owner_id' => $pack->user_id,
            'created_at' => $pack->created_at,
            'updated_at' => $pack->updated_at,
        ];
    }

    private function assertEditable(Request $request, ResourcePack $resourcePack): void
    {
        /** @var User $user */
        $user = $request->user();

        if ($resourcePack->is_public || $resourcePack->user_id !== $user->id) {
            abort(403, 'forbidden');
        }
    }

    private function ensureDefaultPack(): ResourcePack
    {
        $existing = ResourcePack::query()
            ->where('is_default', true)
            ->first();

        if ($existing) {
            if (!$existing->is_public) {
                $existing->update([
                    'is_public' => true,
                ]);
            }
            return $existing;
        }

        $jsonPath = base_path('resources/default_resourcepack.json');
        $contents = File::exists($jsonPath)
            ? File::get($jsonPath)
            : '{}';
        $decoded = json_decode($contents, true);
        $packData = is_array($decoded) ? $decoded : [];

        return ResourcePack::query()->create([
            'user_id' => null,
            'public_id' => Str::uuid()->toString(),
            'name' => 'Default',
            'is_public' => true,
            'is_default' => true,
            'data' => $packData,
        ]);
    }
}
