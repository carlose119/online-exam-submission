import Alpine from 'alpinejs';

// Livewire's synchronous script owns Alpine before this Vite module executes.
if (!window.Livewire) {
    window.Alpine = Alpine;
    Alpine.start();
}
