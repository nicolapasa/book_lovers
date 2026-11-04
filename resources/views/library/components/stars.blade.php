<span class="stars" aria-label="{{ round($rating ?? 0, 1) }} / 5">{{ str_repeat('★', (int) round($rating ?? 0)) }}<span class="dim">{{ str_repeat('★', 5 - (int) round($rating ?? 0)) }}</span></span>
