function iconUrl(code) {
    return /^[0-9]{2}[dn]$/.test(String(code || ''))
        ? `https://openweathermap.org/img/wn/${code}@2x.png`
        : null;
}

function setIcon(image, fallback, code, description) {
    const url = iconUrl(code);

    if (!url) {
        image.hidden = true;
        fallback.hidden = false;
        return;
    }

    image.src = url;
    image.alt = description || 'Weather conditions';
    image.hidden = false;
    fallback.hidden = true;
}

function renderForecast(widget, forecast) {
    const section = widget.querySelector('.lk-dashboard-weather__forecast');
    const list = widget.querySelector('[data-weather-forecast-list]');
    list.replaceChildren();

    if (!Array.isArray(forecast) || forecast.length === 0) {
        section.hidden = true;
        return;
    }

    forecast.forEach((item) => {
        const entry = document.createElement('li');
        const time = document.createElement('time');
        const icon = document.createElement('img');
        const temperature = document.createElement('strong');
        const precipitation = document.createElement('small');

        time.textContent = item.time || '—';
        icon.alt = item.description || 'Forecast conditions';
        icon.loading = 'lazy';
        icon.decoding = 'async';
        const url = iconUrl(item.icon);
        if (url) icon.src = url;
        else icon.hidden = true;
        temperature.textContent = `${Math.round(Number(item.temperature_c))}°`;
        precipitation.textContent = `Rain ${Math.max(0, Number(item.precipitation_chance) || 0)}%`;

        entry.append(time, icon, temperature, precipitation);
        list.append(entry);
    });

    section.hidden = false;
}

function initializeWeather(widget) {
    const status = widget.querySelector('[data-weather-status]');
    const button = widget.querySelector('[data-weather-refresh]');
    const currentIcon = widget.querySelector('[data-weather-icon]');
    const currentIconFallback = widget.querySelector('[data-weather-icon-fallback]');
    const temperature = widget.querySelector('[data-weather-temperature]');
    const condition = widget.querySelector('[data-weather-condition]');
    const location = widget.querySelector('[data-weather-location]');
    let requestInProgress = false;

    const setStatus = (message) => {
        status.textContent = message;
    };

    const fetchWeather = async (latitude, longitude) => {
        setStatus('Getting current conditions…');

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        const response = await fetch(widget.dataset.weatherEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
            },
            body: JSON.stringify({ latitude, longitude }),
        });

        if (!response.ok) throw new Error('Weather request failed');

        const weather = await response.json();
        temperature.textContent = `${Math.round(Number(weather.temperature_c))}°C`;
        condition.textContent = weather.condition || 'Current conditions';
        location.textContent = weather.location || 'Your location';
        widget.querySelector('[data-weather-feels-like]').textContent = `${Math.round(Number(weather.feels_like_c))}°C`;
        widget.querySelector('[data-weather-humidity]').textContent = `${Math.round(Number(weather.humidity_percent))}%`;
        widget.querySelector('[data-weather-wind]').textContent = `${Math.round(Number(weather.wind_kph))} km/h`;

        setIcon(currentIcon, currentIconFallback, weather.icon, weather.condition);
        renderForecast(widget, weather.forecast);

        const updatedAt = Number(weather.observed_at);
        const time = updatedAt > 0
            ? new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(new Date(updatedAt * 1000))
            : '';
        setStatus(time ? `Updated ${time} · Coordinates are not saved to your account.` : 'Coordinates are not saved to your account.');
    };

    const requestLocation = () => {
        if (requestInProgress) return;

        if (!navigator.geolocation) {
            setStatus('Your browser does not support device location.');
            return;
        }

        requestInProgress = true;
        button.disabled = true;
        setStatus('Requesting device location…');

        navigator.geolocation.getCurrentPosition(async ({ coords }) => {
            try {
                await fetchWeather(coords.latitude, coords.longitude);
            } catch (_) {
                setStatus('Weather is temporarily unavailable. Try again shortly.');
            } finally {
                requestInProgress = false;
                button.disabled = false;
            }
        }, (error) => {
            const message = error.code === 1
                ? 'Allow location access in your browser to see local weather.'
                : error.code === 3
                    ? 'Location request timed out. Try again.'
                    : 'Device location is unavailable. Try again.';
            setStatus(message);
            requestInProgress = false;
            button.disabled = false;
        }, {
            enableHighAccuracy: false,
            maximumAge: 300000,
            timeout: 10000,
        });
    };

    button.addEventListener('click', requestLocation);
    requestLocation();
}

document.querySelectorAll('[data-dashboard-weather]').forEach(initializeWeather);
