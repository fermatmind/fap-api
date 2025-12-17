<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Event;

class EventController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            "event_code" => ["required", "string", "max:64"],
            "anon_id"    => ["nullable", "string", "max:128"],
            "attempt_id" => ["required", "string", "max:64"], // 你现在线上就是要求它
            "meta_json"  => ["nullable", "array"],
        ]);

        $event = Event::create([
            "event_code" => $data["event_code"],
            "anon_id"    => $data["anon_id"] ?? null,
            "attempt_id" => $data["attempt_id"],
            "meta_json"  => $data["meta_json"] ?? null,
        ]);

        return response()->json([
            "ok" => true,
            "id" => $event->id,
        ]);
    }
}
