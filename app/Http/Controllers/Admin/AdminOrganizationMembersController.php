<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\OrganizationMembersController;
use Illuminate\Http\Request;

class AdminOrganizationMembersController extends Controller
{
    public function __construct(private OrganizationMembersController $members) {}

    public function index(string $slug)
    {
        return $this->members->index(app()->getLocale(), $slug);
    }

    public function invite(Request $request, string $slug)
    {
        return $this->members->invite($request, app()->getLocale(), $slug);
    }

    public function resend(Request $request, string $slug, int $invitation)
    {
        return $this->members->resend($request, app()->getLocale(), $slug, $invitation);
    }

    public function revoke(Request $request, string $slug, int $invitation)
    {
        return $this->members->revoke($request, app()->getLocale(), $slug, $invitation);
    }

    public function role(Request $request, string $slug, int $member)
    {
        return $this->members->role($request, app()->getLocale(), $slug, $member);
    }

    public function remove(Request $request, string $slug, int $member)
    {
        return $this->members->remove($request, app()->getLocale(), $slug, $member);
    }

    public function transfer(Request $request, string $slug)
    {
        return $this->members->transfer($request, app()->getLocale(), $slug);
    }

    public function leave(Request $request, string $slug)
    {
        return $this->members->leave($request, app()->getLocale(), $slug);
    }

    public function deletion(Request $request, string $slug)
    {
        return $this->members->deletion($request, app()->getLocale(), $slug);
    }
}
