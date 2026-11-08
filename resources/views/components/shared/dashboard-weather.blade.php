<aside
    class="lk-dashboard-weather"
    data-dashboard-weather
    data-weather-endpoint="{{ route('dashboard-weather.current') }}"
    aria-label="Weather near you"
>
    <div class="lk-dashboard-weather__current">
        <div class="lk-dashboard-weather__icon-wrap">
            <img data-weather-icon alt="" hidden>
            <span data-weather-icon-fallback aria-hidden="true">☁</span>
        </div>
        <div class="lk-dashboard-weather__current-copy">
            <span class="lk-dashboard-weather__eyebrow">Weather near you</span>
            <strong data-weather-temperature>—°C</strong>
            <span data-weather-condition>Checking your location…</span>
            <small data-weather-location>Current conditions for your area</small>
        </div>
    </div>

    <dl class="lk-dashboard-weather__metrics" aria-label="Current weather details">
        <div><dt>Feels like</dt><dd data-weather-feels-like>—</dd></div>
        <div><dt>Humidity</dt><dd data-weather-humidity>—</dd></div>
        <div><dt>Wind</dt><dd data-weather-wind>—</dd></div>
    </dl>

    <section class="lk-dashboard-weather__forecast" aria-label="Upcoming forecast" hidden>
        <div class="lk-dashboard-weather__forecast-heading">
            <strong>Upcoming</strong>
            <span>3-hour forecast</span>
        </div>
        <ol data-weather-forecast-list></ol>
    </section>

    <footer class="lk-dashboard-weather__footer">
        <span data-weather-status role="status" aria-live="polite">Weather uses your device location.</span>
        <button type="button" data-weather-refresh>Refresh</button>
        <a href="https://openweathermap.org/" target="_blank" rel="noopener noreferrer">Weather by OpenWeather</a>
    </footer>
</aside>
