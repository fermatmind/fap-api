<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EventController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'event_code'  => ['required', 'string', 'max:64'],
            'anon_id'     => ['nullable', 'string', 'max:128'],
            'attempt_id'  => ['required', 'string', 'max:64'],
            'occurred_at' => ['nullable', 'date'],
            'props'       => ['nullable', 'array'], 
            'meta_json'   => ['nullable', 'array'],
        ]);

        $event = new Event();

        $event->id = method_exists(Str::class, 'uuid7')
            ? (string) Str::uuid7()
            : (string) Str::uuid();

        $event->event_code  = $data['event_code'];
        $event->anon_id     = $data['anon_id'] ?? null;
        $event->attempt_id  = $data['attempt_id'];
        $event->meta_json   = $data['props'] ?? ($data['meta_json'] ?? null);

        $event->occurred_at = !empty($data['occurred_at'])
            ? Carbon::parse($data['occurred_at'])
            : now();

        $event->save();

        return response()->json([
            'ok' => true,
            'id' => $event->id,
        ]);
    }
}
