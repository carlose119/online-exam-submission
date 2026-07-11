# Documento de Requerimientos del Producto (PRD)

## Proyecto: Sistema de Gestión de Aprendizaje y Evaluación Online (LMS-Lite)
**Stack Tecnológico:** Laravel 13, FilamentPHP v5, Livewire v3+, MySQL / PostgreSQL
**Sistema de Almacenamiento:** Disco Local (`public` / `local` disk)

---

## 1. Introducción y Objetivos del Producto
El objetivo de este proyecto es construir una plataforma educativa ligera (LMS-Lite) optimizada para la creación, gestión y presentación de exámenes automatizados en línea, además de servir como repositorio de material de estudio multimedia y centro de reportes de rendimiento. El sistema está diseñado con un backend monolítico en Laravel 13, utilizando **FilamentPHP v5** para los paneles de gestión (Administrador y Profesor) y **Livewire** para una interfaz de estudiante altamente reactiva y personalizada.

---

## 2. Roles de Usuario y Matriz de Permisos

El sistema contará con tres roles estrictos. La autenticación se manejará mediante un único modelo de `User` con un campo `role` (enum/string).

| Módulo / Acción | Administrador | Profesor | Estudiante |
| :--- | :---: | :---: | :---: |
| Registrar y gestionar Profesores | **X** | | |
| Crear Clases y generar Links de Invitación | | **X** | |
| Planificar Planes de Estudio (Syllabus) | | **X** | |
| Cargar Material de Estudio (Local y Enlaces) | | **X** | |
| Diseñar Exámenes, Preguntas y Respuestas | | **X** | |
| Generar Reportes (Plan de Evaluación y Notas) | | **X** | |
| Suscribirse a múltiples clases | | | **X** |
| Consumir material y ver clases en vivo | | | **X** |
| Presentar Exámenes con temporizador | | | **X** |
| Visualizar calificación instantánea | | | **X** |

---

## 3. Especificaciones por Módulo y Requerimientos Funcionales

### 3.1. Módulo del Administrador (Panel Filament v5)
* **Gestión de Docentes:** CRUD completo para dar de alta, editar, suspender o eliminar cuentas de profesores.
* **Campos obligatorios de Profesor:** Nombre completo, Correo electrónico (único), Contraseña (con opción de generar temporal), Estado de la cuenta (Activo/Inactivo).

### 3.2. Módulo del Profesor (Panel Filament v5)
* **Gestión de Clases:** Creación de aulas virtuales asignadas al profesor autenticado. Cada clase debe generar automáticamente un `invitation_code` único (alfanumérico o UUID corto).
* **Link de Invitación:** Exponer un botón para copiar el enlace de suscripción directo, estructurado como: `https://dominio.com/clase/unirse/{invitation_code}`.
* **Planificación del Plan de Estudios:** Un campo de texto enriquecido (Rich Editor de Filament) para detallar el cronograma académico de la clase.
* **Gestión de Materiales de Estudio:**
    * **Archivos Locales:** Carga de documentos (PDF, `.docx`, `.xlsx`) y videos locales (Formatos comunes como MP4).
    * **Enlaces de Terceros:** Campo para registrar URLs externas (identificando videos de YouTube para embeber).
    * **Enlaces de Videoconferencia:** Sección dedicada para registrar links de clases en vivo (Zoom, Microsoft Teams, Google Meet) con título y fecha/hora programada.
* **Módulo de Reportes y Exportación:**
    * **Reporte del Plan de Evaluación:** Un documento exportable que desglose los exámenes planificados, sus descripciones y los valores/puntos asignados.
    * **Reporte de Calificaciones:** Un listado exportable (formatos **PDF** y **Excel/CSV**) que muestre a los estudiantes inscritos en la clase y las notas obtenidas en cada uno de los exámenes presentados.

### 3.3. Módulo de Configuración de Exámenes (Profesor)
* **Datos Generales:** Título del examen, descripción o instrucciones.
* **Temporizador Estricto:** Definición de la duración del examen expresada estrictamente en **minutos** (ej. 60 minutos).
* **Base de Cálculo (Nota Máxima):** Campo numérico entero para definir sobre cuánto se evaluará el examen (ej. Base 20, Base 100).
* **Creador de Preguntas Dinámico:** Un formulario repetidor (Repeater) que permite añadir N preguntas por examen.
    * **Texto de la Pregunta:** Input de texto o área de texto.
    * **Tipo de Pregunta:** Selección entre `Selección Simple` o `Selección Múltiple`.
    * **Valor de la Pregunta:** Puntuación individual (ej. 5 puntos).
* **Opciones de Respuesta:** Sub-repetidor para cada pregunta donde se ingresan las opciones de texto y un interruptor booleano (`is_correct`) para marcar cuáles son las correctas.

### 3.4. Módulo del Estudiante (Interfaz Personalizada con Livewire)
* **Onboarding y Registro por Enlace:**
    * Si un usuario no registrado accede al Link de Invitación, se registra de forma rápida y el sistema lo asocia automáticamente a la clase.
    * Si el estudiante ya tiene una cuenta activa e inicia sesión, el sistema detecta el código, crea la relación de inscripción inmediatamente y lo redirige a su dashboard.
* **Suscripción Multi-Clase (Muchos a Muchos):** El estudiante dispondrá de un Sidebar donde podrá visualizar e interactuar con **todas las clases a las que se ha suscrito**, independientemente de si pertenecen a profesores diferentes.
* **Consumo de Contenido:** Visualización del plan de estudios, descarga de archivos locales, reproducción multimedia y acceso a enlaces de videoconferencia (Zoom/Teams/Meet).
* **Rendición de Exámenes (Componente Livewire):**
    * Interfaz limpia que bloquea la navegación externa una vez iniciado el examen.
    * Un **Temporizador en cuenta regresiva** se renderiza en la pantalla empleando Livewire sincronizado con eventos de Laravel para garantizar que el tiempo restante sea exacto con respecto al reloj del servidor.

---

## 4. Reglas de Negocio y Flujos Críticos

### 4.1. Flujo de Finalización de Examen y Calificación Automática
Un examen activo solo puede cerrarse por dos desencadenantes:
1. **Finalización Manual:** El estudiante responde y hace clic en el botón "Finalizar y Entregar Examen".
2. **Finalización por Agotamiento de Tiempo (Timeout):** Cuando el contador del servidor llega a cero, el backend dispara un evento forzado de auto-envío (`auto-submit`).

**Lógica de Calificación Inmediata (Backend Laravel):**
* El sistema procesa las respuestas del intento (`StudentAttempt`).
* Para *Selección Simple*: Si la opción seleccionada es `is_correct == true`, suma el puntaje completo de la pregunta.
* Para *Selección Múltiple*: Se valida que todas las respuestas correctas hayan sido seleccionadas y ninguna incorrecta.
* La nota final obtenida se calcula y se guarda en `score_obtained`.
* El estado del intento cambia a `Finalizado`, impidiendo cualquier reintento (1 solo intento por alumno por examen).
* **Resultado en Pantalla:** Livewire refresca instantáneamente la interfaz mostrando la calificación obtenida (ej: *"Tu calificación es: 18 / 20"*).

### 4.2. Estrategia de Almacenamiento Local (Local Filesystem)
Dado que el sistema utilizará el **disco local**, el agente de desarrollo debe configurar el storage de la Genes de la siguiente manera:
* Configurar los discos en `config/filesystems.php` apuntando al driver `local` o `public`.
* Todos los archivos multimedia cargados por los profesores se guardarán en directorios estructurados dentro de `storage/app/public/materials/{class_id}/`.
* Se ejecutará obligatoriamente el comando `php artisan storage:link` para exponer de forma pública las URLs de descarga y reproducción de recursos.

---

## 5. Arquitectura de Datos Sugerida (Esquema de Base de Datos)

### 5.1. Tabla: `users`
* `id` (BigInt, PK, Autoincrement)
* `name` (String)
* `email` (String, Unique)
* `password` (String)
* `role` (Enum: 'ADMIN', 'TEACHER', 'STUDENT')
* `timestamps`

### 5.2. Tabla: `classes`
* `id` (BigInt, PK)
* `teacher_id` (BigInt, FK de `users`)
* `title` (String)
* `description` (Text, Nullable)
* `syllabus` (LongText, Nullable)
* `invitation_code` (String, Unique)
* `timestamps`

### 5.3. Tabla Pivote: `class_user` (Suscripciones Multi-Clase)
* `id` (BigInt, PK)
* `class_id` (BigInt, FK de `classes`, onDelete cascade)
* `user_id` (BigInt, FK de `users`, onDelete cascade)
* `timestamps`

### 5.4. Tabla: `study_materials`
* `id` (BigInt, PK)
* `class_id` (BigInt, FK de `classes`)
* `title` (String)
* `type` (Enum: 'FILE', 'LINK', 'MEETING')
* `file_path_or_url` (Text)
* `extra_metadata` (Json, Nullable)
* `timestamps`

### 5.5. Tabla: `exams`
* `id` (BigInt, PK)
* `class_id` (BigInt, FK de `classes`)
* `title` (String)
* `duration_minutes` (Integer)
* `max_score` (Integer)
* `timestamps`

### 5.6. Tabla: `questions`
* `id` (BigInt, PK)
* `exam_id` (BigInt, FK de `exams`)
* `text` (Text)
* `type` (Enum: 'SINGLE', 'MULTIPLE')
* `points` (Decimal/Integer)
* `timestamps`

### 5.7. Tabla: `answer_options`
* `id` (BigInt, PK)
* `question_id` (BigInt, FK de `questions`)
* `text` (Text)
* `is_correct` (Boolean, Default false)
* `timestamps`

### 5.8. Tabla: `student_attempts`
* `id` (BigInt, PK)
* `student_id` (BigInt, FK de `users`)
* `exam_id` (BigInt, FK de `exams`)
* `score_obtained` (Decimal, Nullable)
* `started_at` (Timestamp)
* `finished_at` (Timestamp, Nullable)
* `timestamps`

### 5.9. Tabla: `student_answers`
* `id` (BigInt, PK)
* `student_attempt_id` (BigInt, FK de `student_attempts`)
* `question_id` (BigInt, FK de `questions`)
* `answer_option_id` (BigInt, FK de `answer_options`)

---

## 6. Requerimientos No Funcionales y Seguridad
* **Seguridad del Reloj:** La verificación del tiempo restante debe hacerse comparando el `started_at` guardado en la BD con el `now()` del servidor.
* **Generación Eficiente de Reportes:** Se sugiere integrar componentes de exportación en Filament v5 (usando extensiones nativas o paquetes como `maatwebsite/excel` y `barryvdh/laravel-dompdf`) para generar descargas limpias de los planes de evaluación y las sábanas de notas.
* **Carga de Archivos Pesados:** Configurar `upload_max_filesize` y `post_max_size` en el servidor para permitir la subida de videos locales de gran tamaño.
* **Optimización de UI/UX:** El componente de examen de Livewire debe manejar el estado para permitir la reanudación del examen en caso de desconexión o refresco accidental, siempre que el tiempo en el servidor siga vigente.
