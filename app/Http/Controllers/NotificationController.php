<?php

namespace App\Http\Controllers;

use App\Services\AccessScope;
use App\Services\AuditService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request, AccessScope $scope, AuditService $audit)
    {
        $notifications = $scope->notifications($request->user())->latest()->paginate(20);
        $audit->record('notifications.view', $request->user());

        return view('notifications.index', compact('notifications'));
    }

    public function read(Request $request, AccessScope $scope, AuditService $audit, string $id)
    {
        $notification = $scope->notifications($request->user())->whereKey($id)->firstOrFail();
        $notification->markAsRead();
        $audit->record('notification.read', $request->user(), ['notification_id' => $notification->id]);

        return back()->with('status', 'Notificación marcada como leída.');
    }
}
