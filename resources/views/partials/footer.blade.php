<footer class="site-footer">
    <div class="wrap">
        <span>&copy; {{ date('Y') }} The Lobby</span>
        <nav aria-label="Footer">
            <a href="{{ url('/') }}">Home</a>
            <a href="{{ url('/') }}#start">Create a trip</a>
            <a href="{{ route('join.show') }}">Join a trip</a>
        </nav>
    </div>
</footer>
