<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import type { Map as LeafletMap, CircleMarker } from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { gpsSender, home } from '@/routes';
import { latestPosition as latestPositionUrl } from '@/routes/api/v1/vehicles';

type RouteStop = {
    id: number;
    sequence: number;
    name: string;
    latitude: number;
    longitude: number;
    distance_from_start_m: number | null;
};

const props = defineProps<{
    busRoute: {
        id: number;
        name: string;
        line_name: string;
        geometry: { type: 'LineString'; coordinates: [number, number][] };
        length_m: number;
        stops: RouteStop[];
    } | null;
}>();

const mapContainer = ref<HTMLDivElement | null>(null);
const mapError = ref('');
const tileError = ref(false);
const distanceFormatter = new Intl.NumberFormat('es-AR', {
    maximumFractionDigits: 1,
});
let map: LeafletMap | undefined;
let unmounted = false;

type VehiclePosition = {
    bus_route_id: number | null;
    latitude: number;
    longitude: number;
    accuracy_m: number | null;
    recorded_at: string;
    received_at: string;
};
type RouteTracking = {
    status: string;
    tolerance_m: number;
    distance_to_route_m: number | null;
    projected_point: { latitude: number; longitude: number } | null;
    progress_m: number | null;
    progress_percent: number | null;
    previous_stop: { id: number; sequence: number; name: string } | null;
    next_stop: { id: number; sequence: number; name: string } | null;
    distance_to_next_stop_m: number | null;
};
const routeTracking = ref<RouteTracking | null>(null);
const arrivalEstimate = ref<{
    seconds: number | null;
    assumed_speed_kmh: number | null;
    status: string;
} | null>(null);
const routeStatusLabels: Record<string, string> = {
    on_route: 'Dentro de la tolerancia del recorrido',
    off_route: 'Fuera de la tolerancia del recorrido',
    poor_accuracy: 'Precisión GPS insuficiente para estimar la próxima parada',
    no_route: 'La unidad no tiene recorrido asignado',
    route_changed:
        'El recorrido cambió; falta una medición de la nueva asignación',
    inactive_route: 'El recorrido está inactivo',
    stops_not_ready: 'Falta calcular la distancia de alguna parada',
};
let projectedMarker: CircleMarker | undefined;
const vehicleCode = ref('PRUEBA-01');
const token = ref('');
const monitoring = ref(false);
const following = ref(true);
const position = ref<VehiclePosition | null>(null);
const trackingError = ref('');
const updatedAt = ref('');
let vehicleMarker: CircleMarker | undefined;
let leafletApi: typeof import('leaflet') | undefined;
let pollTimer: ReturnType<typeof setInterval> | undefined;
let pollRequest: AbortController | undefined;
let monitoringSession = 0;

function drawVehicle(): void {
    if (!map || !leafletApi || !position.value) return;
    const coordinates: [number, number] = [
        position.value.latitude,
        position.value.longitude,
    ];
    if (!vehicleMarker) {
        vehicleMarker = leafletApi
            .circleMarker(coordinates, {
                radius: 10,
                color: '#991b1b',
                fillColor: '#ef4444',
                fillOpacity: 1,
                weight: 3,
            })
            .addTo(map);
        const label = document.createElement('span');
        label.textContent = vehicleCode.value;
        vehicleMarker.bindTooltip(label, {
            permanent: true,
            direction: 'bottom',
        });
    } else {
        vehicleMarker.setLatLng(coordinates);
    }
    if (following.value) map.panTo(coordinates);
    const projected = routeTracking.value?.projected_point;
    if (projected && position.value.bus_route_id === props.busRoute?.id) {
        const point: [number, number] = [
            projected.latitude,
            projected.longitude,
        ];
        if (!projectedMarker) {
            projectedMarker = leafletApi
                .circleMarker(point, {
                    radius: 5,
                    color: '#92400e',
                    fillColor: '#facc15',
                    fillOpacity: 1,
                    weight: 2,
                })
                .bindTooltip('Posición estimada en el recorrido')
                .addTo(map);
        } else {
            projectedMarker.setLatLng(point);
        }
    } else {
        projectedMarker?.remove();
        projectedMarker = undefined;
    }
}

function stopMonitoring(): void {
    monitoringSession++;
    monitoring.value = false;
    if (pollTimer !== undefined) window.clearInterval(pollTimer);
    pollTimer = undefined;
    pollRequest?.abort();
    pollRequest = undefined;
}

async function refreshPosition(activeSession: number): Promise<void> {
    if (pollRequest || !monitoring.value) return;
    const controller = new AbortController();
    pollRequest = controller;
    const timeout = window.setTimeout(() => controller.abort(), 10000);
    try {
        const response = await fetch(
            latestPositionUrl.url({ vehicle: vehicleCode.value.trim() }),
            {
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${token.value.trim()}`,
                },
                cache: 'no-store',
                signal: controller.signal,
            },
        );
        if (activeSession !== monitoringSession) return;
        if (!response.ok) {
            trackingError.value =
                response.status === 401
                    ? 'Token incorrecto.'
                    : response.status === 404
                      ? 'No se encontró una unidad activa con ese código.'
                      : `No se pudo consultar la posición (${response.status}).`;
            if ([401, 404, 503].includes(response.status)) stopMonitoring();
            return;
        }
        const payload = (await response.json()) as {
            data: VehiclePosition | null;
            route_tracking?: RouteTracking;
            arrival_estimate?: {
                seconds: number | null;
                assumed_speed_kmh: number | null;
                status: string;
            };
        };
        if (activeSession !== monitoringSession) return;
        position.value = payload.data;
        routeTracking.value = payload.route_tracking ?? null;
        arrivalEstimate.value = payload.arrival_estimate ?? null;
        updatedAt.value = new Date().toLocaleTimeString('es-AR');
        trackingError.value = '';
        drawVehicle();
    } catch {
        if (activeSession === monitoringSession)
            trackingError.value =
                'No se pudo actualizar. Se reintentará automáticamente.';
    } finally {
        window.clearTimeout(timeout);
        if (activeSession === monitoringSession) pollRequest = undefined;
    }
}

function startMonitoring(): void {
    if (!token.value.trim() || !vehicleCode.value.trim()) {
        trackingError.value =
            'Ingresá la unidad y el token para consultar su ubicación.';
        return;
    }
    vehicleMarker?.remove();
    vehicleMarker = undefined;
    projectedMarker?.remove();
    projectedMarker = undefined;
    position.value = null;
    routeTracking.value = null;
    arrivalEstimate.value = null;
    trackingError.value = '';
    monitoring.value = true;
    const activeSession = ++monitoringSession;
    void refreshPosition(activeSession);
    pollTimer = window.setInterval(() => {
        void refreshPosition(activeSession);
    }, 5000);
}

function formatDistance(distance: number | null): string {
    return distance === null
        ? 'Sin calcular'
        : `${distanceFormatter.format(distance)} m`;
}

onMounted(async () => {
    if (!props.busRoute || !mapContainer.value) {
        return;
    }

    try {
        const leaflet = await import('leaflet');
        leafletApi = leaflet;

        if (unmounted || !mapContainer.value || !props.busRoute) {
            return;
        }

        map = leaflet.map(mapContainer.value);
        leaflet
            .tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution:
                    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            })
            .on('tileerror', () => {
                tileError.value = true;
            })
            .addTo(map);

        const routeLayer = leaflet.geoJSON(props.busRoute.geometry, {
            style: { color: '#2563eb', weight: 5 },
        });

        map.fitBounds(routeLayer.getBounds(), {
            padding: [35, 35],
            maxZoom: 18,
        });
        routeLayer.addTo(map);

        for (const stop of props.busRoute.stops) {
            const popup = document.createElement('p');
            popup.textContent = `${stop.sequence}. ${stop.name} · ${formatDistance(stop.distance_from_start_m)} desde el inicio`;

            leaflet
                .circleMarker([stop.latitude, stop.longitude], {
                    radius: 8,
                    color: '#14532d',
                    fillColor: '#22c55e',
                    fillOpacity: 1,
                    weight: 2,
                })
                .bindTooltip(String(stop.sequence), {
                    permanent: true,
                    direction: 'top',
                })
                .bindPopup(popup)
                .addTo(map);
        }

        leaflet.control.scale({ imperial: false }).addTo(map);
        drawVehicle();
    } catch (error) {
        console.error('Error al inicializar el mapa del recorrido:', error);
        mapError.value = 'No se pudo cargar el mapa. Probá recargar la página.';
    }
});

onBeforeUnmount(() => {
    unmounted = true;
    stopMonitoring();
    map?.remove();
});
</script>

<template>
    <main class="min-h-screen bg-background p-4 text-foreground sm:p-6">
        <Head title="Recorrido de prueba" />
        <div class="mx-auto flex max-w-6xl flex-col gap-6">
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex flex-col gap-2">
                    <h1 class="text-2xl font-semibold">Recorrido de prueba</h1>
                    <p class="text-muted-foreground">
                        La Banda, Santiago del Estero
                    </p>
                </div>
                <Link :href="home()" class="text-sm underline">
                    Volver al inicio
                </Link>
            </header>

            <template v-if="busRoute">
                <section class="flex flex-col gap-3 rounded-lg border p-4">
                    <h2 class="text-lg font-medium">
                        Seguimiento del teléfono
                    </h2>
                    <div class="flex flex-wrap items-end gap-3">
                        <label class="flex flex-col gap-1 text-sm"
                            >Unidad
                            <input
                                v-model="vehicleCode"
                                :disabled="monitoring"
                                class="rounded-md border bg-background p-2"
                            />
                        </label>
                        <label class="flex flex-col gap-1 text-sm"
                            >Token de prueba
                            <input
                                v-model="token"
                                type="password"
                                autocomplete="off"
                                :disabled="monitoring"
                                class="rounded-md border bg-background p-2"
                            />
                        </label>
                        <button
                            v-if="!monitoring"
                            type="button"
                            class="rounded-md bg-primary px-4 py-2 text-primary-foreground"
                            @click="startMonitoring"
                        >
                            Ver ubicación
                        </button>
                        <button
                            v-else
                            type="button"
                            class="rounded-md border px-4 py-2"
                            @click="stopMonitoring"
                        >
                            Detener actualización
                        </button>
                    </div>
                    <label class="flex items-center gap-2 text-sm"
                        ><input v-model="following" type="checkbox" /> Seguir al
                        teléfono en el mapa</label
                    >
                    <p
                        v-if="trackingError"
                        role="alert"
                        class="text-destructive"
                    >
                        {{ trackingError }}
                    </p>
                    <p role="status" class="text-sm">
                        {{
                            monitoring
                                ? position
                                    ? 'Actualizando cada cinco segundos.'
                                    : 'Esperando la primera posición del teléfono…'
                                : 'Actualización detenida.'
                        }}
                    </p>
                    <p v-if="position" class="text-sm text-muted-foreground">
                        Medición:
                        {{
                            new Date(position.recorded_at).toLocaleString(
                                'es-AR',
                            )
                        }}
                        · Precisión: {{ formatDistance(position.accuracy_m) }} ·
                        Última consulta: {{ updatedAt }}.
                    </p>
                    <div
                        v-if="routeTracking"
                        class="flex flex-col gap-2 rounded-md bg-muted p-3 text-sm"
                    >
                        <p class="font-medium">
                            Estimación sobre la última medición GPS
                        </p>
                        <p>
                            {{
                                routeStatusLabels[routeTracking.status] ??
                                'Estimación no disponible'
                            }}
                        </p>
                        <p>
                            Tolerancia de prueba:
                            {{ formatDistance(routeTracking.tolerance_m) }} ·
                            Separación del recorrido:
                            {{
                                formatDistance(
                                    routeTracking.distance_to_route_m,
                                )
                            }}
                        </p>
                        <template v-if="routeTracking.progress_m !== null">
                            <p>
                                Progreso:
                                {{ formatDistance(routeTracking.progress_m) }}
                                desde el inicio ({{
                                    routeTracking.progress_percent?.toFixed(1)
                                }}
                                %)
                            </p>
                            <p>
                                Parada anterior estimada:
                                {{
                                    routeTracking.previous_stop?.name ??
                                    'Ninguna'
                                }}
                            </p>
                            <p v-if="routeTracking.next_stop">
                                Próxima parada estimada:
                                {{ routeTracking.next_stop.name }}
                                ·
                                {{
                                    formatDistance(
                                        routeTracking.distance_to_next_stop_m,
                                    )
                                }}
                                siguiendo el recorrido
                            </p>
                            <p v-else-if="routeTracking.status === 'on_route'">
                                No quedan paradas activas por delante.
                            </p>
                        </template>
                        <p v-else>
                            Próxima parada: no disponible para esta lectura.
                        </p>
                        <p class="text-muted-foreground">
                            Se supone avance desde la primera parada hacia la
                            última. El sentido de marcha todavía no se verifica.
                        </p>
                        <p
                            v-if="arrivalEstimate?.seconds != null"
                            class="font-medium"
                        >
                            ETA de prueba hasta la próxima parada:
                            {{
                                arrivalEstimate.seconds < 60
                                    ? 'menos de un minuto'
                                    : `aproximadamente ${Math.round(arrivalEstimate.seconds / 60)} min`
                            }}
                            si continúa a
                            {{ arrivalEstimate.assumed_speed_kmh }} km/h.
                        </p>
                        <p
                            v-else-if="
                                arrivalEstimate?.status === 'unreliable_time'
                            "
                        >
                            ETA no disponible: la medición tiene más de un
                            minuto o su hora está adelantada.
                        </p>
                        <p v-else-if="arrivalEstimate">
                            ETA no disponible para esta lectura.
                        </p>
                        <p class="text-muted-foreground">
                            El ETA usa una velocidad asumida; todavía no
                            contempla detenciones, tráfico ni cambios de
                            velocidad.
                        </p>
                    </div>
                    <Link :href="gpsSender()" class="text-sm underline"
                        >Abrir pantalla de envío en el celular</Link
                    >
                </section>
                <section class="flex flex-col gap-2">
                    <h2 class="text-lg font-medium">{{ busRoute.name }}</h2>
                    <p class="text-sm text-muted-foreground">
                        {{ busRoute.line_name }} ·
                        {{ formatDistance(busRoute.length_m) }} de recorrido
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Puntos de prueba junto a la plaza y las intersecciones;
                        no representan paradas oficiales.
                    </p>
                </section>

                <p v-if="mapError" role="alert" class="text-destructive">
                    {{ mapError }}
                </p>
                <p v-if="tileError" role="status" class="text-sm">
                    Algunas imágenes del mapa no pudieron cargarse. Revisá la
                    conexión; el recorrido y las paradas siguen disponibles.
                </p>

                <div class="grid gap-6 lg:grid-cols-3">
                    <section class="flex flex-col gap-2 lg:col-span-2">
                        <h2 class="text-lg font-medium">Mapa del recorrido</h2>
                        <div
                            ref="mapContainer"
                            :aria-label="`Mapa del recorrido ${busRoute.name}`"
                            class="relative z-0 h-[60vh] min-h-80 rounded-lg border"
                        />
                        <p class="text-sm text-muted-foreground">
                            Línea azul: recorrido. Círculos verdes: paradas en
                            orden. Círculo rojo: última ubicación del teléfono.
                            Punto amarillo: posición estimada sobre el
                            recorrido. Tocá una parada para ver su nombre.
                        </p>
                    </section>

                    <section class="flex flex-col gap-3">
                        <h2 class="text-lg font-medium">Paradas en orden</h2>
                        <ol class="flex flex-col gap-3">
                            <li
                                v-for="stop in busRoute.stops"
                                :key="stop.id"
                                class="rounded-lg border p-3"
                            >
                                <p class="font-medium">
                                    {{ stop.sequence }}. {{ stop.name }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {{
                                        formatDistance(
                                            stop.distance_from_start_m,
                                        )
                                    }}
                                    desde el inicio
                                </p>
                            </li>
                        </ol>
                    </section>
                </div>
            </template>

            <p v-else class="rounded-lg border p-6">
                Todavía no hay un recorrido de prueba cargado.
            </p>
        </div>
    </main>
</template>
