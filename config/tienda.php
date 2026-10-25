<?php

// Datos de la tienda que usan el catálogo, el SEO y el pedido por WhatsApp.
return [
    'nombre' => env('TIENDA_NOMBRE', 'FAStore'),
    'descripcion' => env('TIENDA_DESCRIPCION', 'Tecnología, tarjetas NFC, figuras de acción y más. Entrega gratis en estaciones del tren de Lima.'),
    // URL pública de la tienda (fastore-web): canonical, Open Graph y sitemap.
    'url' => rtrim((string) env('TIENDA_URL', 'https://shop.juarezcampos.net'), '/'),
    // URL pública de esta API: las imágenes se sirven desde aquí (/api/media/...).
    'api_url' => rtrim((string) env('TIENDA_API_URL', env('APP_URL', 'http://127.0.0.1:8010')), '/'),
    'whatsapp' => env('TIENDA_WHATSAPP', '51966422599'),
    'moneda' => 'PEN',
    // Un carrito con productos y sin pedido enviado se considera abandonado pasado este tiempo.
    'abandono_horas' => (int) env('TIENDA_ABANDONO_HORAS', 2),
    // Estaciones de la Línea 1 donde se entrega.
    'estaciones' => ['Villa El Salvador', 'La Cultura', 'Grau'],
    'pagos' => ['Yape', 'Plin', 'Efectivo', 'Transferencia'],

    // Lugar de entrega. "detalle" se muestra al cliente en el checkout y en la cotización.
    'entregas' => [
        'estacion' => ['nombre' => 'Estación del Tren', 'detalle' => 'Gratis en las estaciones Villa El Salvador, La Cultura y Grau (Línea 1).'],
        'tienda' => ['nombre' => 'Recojo en tienda', 'detalle' => env('TIENDA_RECOJO', 'Gratis. Te enviamos la dirección y el horario por WhatsApp.')],
        // El costo del envío local no se suma al total: se paga al momento de la entrega.
        'local' => ['nombre' => 'Envío a nivel local', 'detalle' => 'Delivery en Lima con costo adicional según el distrito. El envío se paga al momento de la entrega (no está incluido en el total).'],
        'nacional' => ['nombre' => 'Envío a nivel nacional (Shalom)', 'detalle' => 'Enviamos por Shalom a su agencia más cercana. El flete se paga al recoger o se coordina por WhatsApp.'],
    ],
    // Días de vigencia de una cotización.
    'cotizacion_dias' => (int) env('TIENDA_COTIZACION_DIAS', 7),

    // Datos del titular para las políticas, los términos y el Libro de Reclamaciones (obligatorios en Perú).
    'legal' => [
        'titular' => env('TIENDA_TITULAR', 'Jean Piere Juarez Campos'),
        'documento' => env('TIENDA_RUC', ''),
        'domicilio' => env('TIENDA_DOMICILIO', 'Lima, Perú'),
        'correo' => env('TIENDA_CORREO', 'contacto@juarezcampos.net'),
        // Plazo legal de respuesta del Libro de Reclamaciones (D.S. 101-2022-PCM: 15 días hábiles).
        'plazo_reclamo_dias' => 15,
    ],
];
