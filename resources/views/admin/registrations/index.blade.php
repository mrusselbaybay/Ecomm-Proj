@extends('layouts.admin')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Account Registrations</h1>
        <span class="bg-amber-100 text-amber-800 px-3 py-1 rounded-full text-sm">
            {{ $counts['pending'] }} pending
        </span>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <form method="GET" class="flex gap-4 flex-wrap">
            <input type="text" name="search" placeholder="Search..." value="{{ request('search') }}" class="field-input">
            <select name="role" class="field-input w-40">
                <option value="">All Roles</option>
                <option value="buyer" {{ request('role') == 'buyer' ? 'selected' : '' }}>Buyer</option>
                <option value="seller" {{ request('role') == 'seller' ? 'selected' : '' }}>Seller</option>
                <option value="courier" {{ request('role') == 'courier' ? 'selected' : '' }}>Courier</option>
            </select>
            <select name="status" class="field-input w-40">
                <option value="">All Status</option>
                <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="rejected" {{ request('status') == 'rejected' ? 'selected' : '' }}>Rejected</option>
            </select>
            <button type="submit" class="btn-gradient text-white px-4 py-2 rounded-lg">Filter</button>
        </form>
    </div>

    <!-- Applications Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Applicant</th>
                    <th>Role</th>
                    <th>Email</th>
                    <th>Submitted</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($applications as $app)
                <tr>
                    <td class="font-medium">{{ $app->full_name }}</td>
                    <td>{{ ucfirst($app->role) }}</td>
                    <td>{{ $app->email }}</td>
                    <td>{{ $app->created_at->diffForHumans() }}</td>
                    <td>
                        <span class="badge {{ $app->status == 'pending' ? 'badge-amber' : 'badge-red' }}">
                            {{ ucfirst($app->status) }}
                        </span>
                    </td>
                    <td>
                        <div class="flex gap-2">
                            <a href="{{ route('admin.registrations.show', $app) }}" class="btn-outline">View</a>
                            @if($app->status == 'pending')
                                <form action="{{ route('admin.registrations.approve', $app) }}" method="POST">
                                    @csrf
                                    <button class="btn-sm-gradient">Approve</button>
                                </form>
                                <button onclick="rejectUser('{{ $app->id }}')" class="btn-danger-outline">Reject</button>
                            @endif
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        {{ $applications->links() }}
    </div>
</div>

<script>
function rejectUser(id) {
    const reason = prompt('Reason for rejection:');
    if (reason) {
        fetch(`/admin/registrations/${id}/reject`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ reason })
        }).then(() => location.reload());
    }
}
</script>
@endsection