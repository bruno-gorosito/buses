<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';
import { store } from '@/actions/App/Http/Controllers/GpsPositionController';
import { testRouteMap } from '@/routes';

const vehicleCode = ref('PRUEBA-01');
const token = ref('');
const running = ref(false);
const sending = ref(false);
const error = ref('');
const sentCount = ref(0);
const lastSent = ref('');
const reading = ref<GeolocationPosition | null>(null);
let watchId: number | undefined;
let request: AbortController | undefined;
let lastAttempt = -Infinity;
let session = 0;

function stop(): void {
    session++;
    running.value = false;
    if (watchId !== undefined) {
        navigator.geolocation.clearWatch(watchId);
        watchId = undefined;
    }
    request?.abort();
    sending.value = false;
}

async function sendPosition(
    position: GeolocationPosition,
    activeSession: number,
): Promise<void> {
    if (activeSession !== session) return;
    reading.value = position;
    if (
        !running.value ||
        sending.value ||
        performance.now() - lastAttempt < 5000
    ) {
        return;
    }
    lastAttempt = performance.now();
    sending.value = true;
    const controller = new AbortController();
    request = controller;
    const timeout = window.setTimeout(() => controller.abort(), 10000);
    const { latitude, longitude, accuracy, speed, heading } = position.coords;
    const rounded = (value: number | null): number | null =>
        value !== null && Number.isFinite(value)
            ? Math.round(value * 1000) / 1000
            : null;

    try {
        const response = await fetch(store.url(), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token.value.trim()}`,
            },
            body: JSON.stringify({
                vehicle_code: vehicleCode.value.trim(),
                latitude,
                longitude,
                recorded_at: new Date(position.timestamp).toISOString(),
                accuracy_m: rounded(accuracy),
                speed_mps: speed !== null && speed >= 0 ? rounded(speed) : null,
                heading_deg:
                    heading !== null && heading >= 0
                        ? (rounded(heading) ?? 0) % 360
                        : null,
            }),
            signal: controller.signal,
        });
        if (activeSession !== session) return;
        if (!response.ok) {
            error.value =
                response.status === 401
                    ? 'Token incorrecto. Detené el envío y revisalo.'
                    : response.status === 422
                      ? 'La unidad o los datos GPS no son válidos. Revisá el código de la unidad.'
                      : `El servidor rechazó la posición (${response.status}).`;
            if ([401, 422, 503].includes(response.status)) stop();
            return;
        }
        error.value = '';
        sentCount.value++;
        lastSent.value = new Date().toLocaleTimeString('es-AR');
    } catch {
        if (activeSession === session) {
            error.value =
                'No se pudo enviar. Se intentará nuevamente con la próxima lectura GPS.';
        }
    } finally {
        window.clearTimeout(timeout);
        if (activeSession === session) sending.value = false;
    }
}

function start(): void {
    error.value = '';
    if (!window.isSecureContext) {
        error.value =
            'Abrí esta pantalla mediante HTTPS para habilitar el GPS del celular.';
        return;
    }
    if (!navigator.geolocation) {
        error.value = 'Este navegador no ofrece acceso a la ubicación.';
        return;
    }
    if (!token.value.trim() || !vehicleCode.value.trim()) {
        error.value = 'Ingresá el código de unidad y el token.';
        return;
    }
    running.value = true;
    lastAttempt = -Infinity;
    const activeSession = ++session;
    watchId = navigator.geolocation.watchPosition(
        (position) => {
            void sendPosition(position, activeSession);
        },
        (failure) => {
            if (activeSession !== session) return;
            error.value =
                failure.code === 1
                    ? 'Permiso de ubicación denegado. Habilitalo en el navegador.'
                    : 'No se pudo obtener el GPS. Revisá la ubicación del teléfono; se seguirá intentando.';
            if (failure.code === 1) stop();
        },
        { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 },
    );
}

onBeforeUnmount(stop);
</script>

<template>
    <main class="min-h-screen bg-background p-6 text-foreground">
        <Head title="GPS del teléfono" />
        <div class="mx-auto flex max-w-lg flex-col gap-5">
            <h1 class="text-2xl font-semibold">GPS del teléfono</h1>
            <p class="text-sm text-muted-foreground">
                Mantené esta pantalla abierta y el teléfono desbloqueado durante
                la prueba. Se envían lecturas nuevas como máximo cada cinco
                segundos.
            </p>
            <label class="flex flex-col gap-2">
                Unidad
                <input
                    v-model="vehicleCode"
                    :disabled="running"
                    class="rounded-md border bg-background p-3"
                />
            </label>
            <label class="flex flex-col gap-2">
                Token de prueba
                <input
                    v-model="token"
                    type="password"
                    autocomplete="off"
                    :disabled="running"
                    class="rounded-md border bg-background p-3"
                />
            </label>
            <p class="text-sm text-muted-foreground">
                El token permanece solamente en esta pantalla.
            </p>
            <button
                v-if="!running"
                type="button"
                class="rounded-md bg-primary p-3 text-primary-foreground"
                @click="start"
            >
                Iniciar envío GPS
            </button>
            <button
                v-else
                type="button"
                class="rounded-md border p-3"
                @click="stop"
            >
                Detener envío
            </button>
            <p role="status">
                {{
                    running
                        ? sending
                            ? 'Enviando posición…'
                            : 'GPS activo, esperando lecturas…'
                        : 'Envío detenido'
                }}
            </p>
            <p v-if="error" role="alert" class="text-destructive">
                {{ error }}
            </p>
            <dl
                v-if="reading"
                class="grid grid-cols-2 gap-3 rounded-lg border p-4 text-sm"
            >
                <dt>Latitud</dt>
                <dd>{{ reading.coords.latitude.toFixed(7) }}</dd>
                <dt>Longitud</dt>
                <dd>{{ reading.coords.longitude.toFixed(7) }}</dd>
                <dt>Precisión informada</dt>
                <dd>{{ reading.coords.accuracy.toFixed(1) }} m</dd>
                <dt>Medición</dt>
                <dd>
                    {{
                        new Date(reading.timestamp).toLocaleTimeString('es-AR')
                    }}
                </dd>
                <dt>Posiciones enviadas</dt>
                <dd>{{ sentCount }}</dd>
                <dt>Último envío</dt>
                <dd>{{ lastSent || 'Todavía ninguno' }}</dd>
            </dl>
            <Link :href="testRouteMap()" class="text-sm underline"
                >Ver mapa de seguimiento</Link
            >
        </div>
    </main>
</template>
