@extends('layouts.app')

@section('title', 'Vault - '.$trip->name)

@section('header_right')
    <a href="{{ route('lobby', $trip->join_code) }}">Back to lobby</a>
@endsection

@section('content')
    <h1>Vault</h1>
    <p class="muted">{{ $trip->name }} &middot; tickets, passports and other private files. Only people in this trip can open them.</p>

    @if (session('status'))
        <div class="flash ok" role="status">{{ session('status') }}</div>
    @endif
    @if (session('problem'))
        <div class="flash error" role="alert">{{ session('problem') }}</div>
    @endif
    @if ($errors->any())
        <div class="flash error" role="alert">{{ $errors->first() }}</div>
    @endif

    @if ($trip->hasEnded())
        <section class="card">
            <h2>This trip has ended</h2>
            <p>The vault is closed and every file has been deleted from storage. The history stays in the audit log.</p>
        </section>
    @else
        @if ($me->canUpload())
            <section class="card">
                <h2>Add a file</h2>
                <form method="POST" action="{{ route('vault.upload', $trip->join_code) }}" enctype="multipart/form-data" id="upload-form">
                    @csrf
                    <label for="file">PDF or photo, up to {{ $maxMb }} MB</label>
                    {{-- image/* lets a phone offer the camera and photo library; the server checks the real type again. --}}
                    <input type="file" id="file" name="file" required accept="application/pdf,image/*,.heic,.heif">
                    <button type="submit" id="upload-button">Upload</button>
                </form>
            </section>
        @else
            <p class="muted">You are a viewer: you can open files but not add them.</p>
        @endif

        <h2>Files ({{ $files->count() }})</h2>
        @forelse ($files as $f)
            <section class="card file-row">
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
            </section>
        @empty
            <p class="muted">No files yet.</p>
        @endforelse

        @if ($me->isOrganiser())
            <section class="card">
                <h2>End trip now</h2>
                <p class="muted small">Closes the vault and permanently deletes every file. This also happens by itself after the trip's end date ({{ $trip->ends_on->format('j M Y') }}).</p>
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
    <script src="{{ asset('js/confirm.js') }}"></script>
    <script src="{{ asset('js/vault.js') }}"></script>
@endpush
