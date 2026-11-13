@extends('layouts.app')

@section('title', 'Vault: '.$trip->name)
@section('description', 'Private files for '.$trip->name.'.')

@push('head')
    <meta name="robots" content="noindex">
@endpush

@section('content')
    <div class="page-head">
        <p class="kicker">{{ $trip->name }}</p>
        <h1>Vault</h1>
        <p class="muted measure">Tickets, passports and other private files. Only people in this trip can open them, and every file is deleted when the trip ends.</p>
    </div>

    @if ($trip->hasEnded())
        <section class="block">
            <h2>This trip has ended</h2>
            <p class="measure">The vault is closed and every file has been deleted from storage. The history stays in the activity log.</p>
        </section>
    @else
        @if ($me->canUpload())
            <section class="block" aria-labelledby="add-title">
                <h2 id="add-title">Add a file</h2>
                <form method="POST" action="{{ route('vault.upload', $trip->join_code) }}" enctype="multipart/form-data" id="upload-form" class="measure">
                    @csrf
                    <label for="file">PDF or photo, up to {{ $maxMb }} MB</label>
                    {{-- image/* lets a phone offer the camera and photo library; the server checks the real type again. --}}
                    <input type="file" id="file" name="file" required accept="application/pdf,image/*,.heic,.heif">
                    <button type="submit" id="upload-button">Upload</button>
                </form>
            </section>
        @else
            <p class="muted">You are a viewer. You can open files but not add them.</p>
        @endif

        <section class="block" aria-labelledby="files-title">
            <h2 id="files-title">Files ({{ $files->count() }})</h2>
            @forelse ($files as $f)
                <div class="row">
                    <div>
                        {{-- The link goes to OUR app, which checks you and then forwards you to a fresh 60-second link. --}}
                        <a href="{{ route('vault.open', [$trip->join_code, $f->id]) }}" target="_blank" rel="noopener"><strong>{{ $f->original_name }}</strong></a>
                        <p class="muted small">
                            {{ number_format($f->size_bytes / 1024 / 1024, 2) }} MB
                            &middot; added by {{ $f->uploadedBy?->name ?? 'someone who left' }}
                            &middot; {{ $f->created_at->format('j M, H:i') }}
                        </p>
                    </div>
                    @if ($me->isOrganiser())
                        <form method="POST" action="{{ route('vault.delete', [$trip->join_code, $f->id]) }}" class="inline"
                              data-confirm="Delete {{ $f->original_name }}? It is removed from storage and cannot be recovered.">
                            @csrf
                            <button type="submit" class="danger">Delete</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="muted">No files yet.</p>
            @endforelse
        </section>

        @if ($me->isOrganiser())
            <section class="danger-zone" aria-labelledby="end-title">
                <h2 id="end-title">End trip now</h2>
                @if ($trip->awaiting_booking)
                    <p class="muted measure">Closes the vault and permanently deletes every file. This also happens by itself the day after the trip's end date, which will come from the booking.</p>
                @else
                    <p class="muted measure">Closes the vault and permanently deletes every file. This also happens by itself the day after the trip's end date ({{ $trip->ends_on->format('j M Y') }}).</p>
                @endif
                <form method="POST" action="{{ route('vault.end', $trip->join_code) }}"
                      data-confirm="End the trip now? EVERY file in the vault will be deleted forever.">
                    @csrf
                    <button type="submit" class="danger">End trip now</button>
                </form>
            </section>
        @endif
    @endif
@endsection

@push('scripts')
    <script src="{{ \App\Support\Assets::url('js/confirm.js') }}"></script>
    <script src="{{ \App\Support\Assets::url('js/vault.js') }}"></script>
@endpush
