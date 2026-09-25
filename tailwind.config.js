module.exports = {
  darkMode: 'class',
  content: ['./**/*.php', './assets/js/**/*.js'],
  theme: {
    extend: {
      colors: {
        surface: {
          // 950 is the page/body canvas (main content only - the sidebar is pinned to
          // 900 regardless of theme, see includes/sidebar.php). Lightened from #0b0b0f
          // so dark-mode pages read as a soft charcoal rather than near-black; cards
          // and inputs (900/800) are unchanged and stay visually distinct via their
          // existing border/shadow treatment.
          950: '#17171d',
          900: '#121218',
          800: '#1a1a22',
        },
        accent: {
          300: 'rgb(var(--color-accent-300) / <alpha-value>)',
          400: 'rgb(var(--color-accent-400) / <alpha-value>)',
          500: 'rgb(var(--color-accent-500) / <alpha-value>)',
          600: 'rgb(var(--color-accent-600) / <alpha-value>)',
          700: 'rgb(var(--color-accent-700) / <alpha-value>)',
          800: 'rgb(var(--color-accent-800) / <alpha-value>)',
        },
        // CSC brand colors, used for confirmation dialogs/modals and notifications (toasts).
        'csc-navy': {
          300: '#8791d1',
          500: '#3f4aad',
          600: '#31399a',
        },
        'csc-red': {
          400: '#f4707d',
          500: '#f04555',
          600: '#ec1c2d',
        },
      },
      keyframes: {
        floatA: {
          '0%, 100%': { transform: 'translate(0, 0) scale(1)' },
          '50%': { transform: 'translate(30px, -40px) scale(1.06)' },
        },
        floatB: {
          '0%, 100%': { transform: 'translate(0, 0) scale(1)' },
          '50%': { transform: 'translate(-30px, 30px) scale(0.94)' },
        },
        floatC: {
          '0%, 100%': { transform: 'translate(0, 0)' },
          '50%': { transform: 'translate(22px, 26px)' },
        },
        driftParticle: {
          '0%, 100%': { transform: 'translateY(0)', opacity: '0.35' },
          '50%': { transform: 'translateY(-18px)', opacity: '0.9' },
        },
        fadeInUp: {
          '0%': { opacity: '0', transform: 'translateY(16px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        fadeIn: {
          '0%': { opacity: '0' },
          '100%': { opacity: '1' },
        },
        // A small icon falling from above and settling into place: overshoots
        // its resting position slightly (55%), rebounds just past it the other
        // way (80%), then comes to rest — used for the action-button icons on
        // admin/lookup.php so they read as physically "dropping into" the row.
        dropIn: {
          '0%': { opacity: '0', transform: 'translateY(-14px)' },
          '55%': { opacity: '1', transform: 'translateY(3px)' },
          '80%': { transform: 'translateY(-1px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        glowPulse: {
          '0%, 100%': { opacity: '0.45' },
          '50%': { opacity: '0.8' },
        },
        progressIndeterminate: {
          '0%': { transform: 'translateX(-100%)' },
          '100%': { transform: 'translateX(400%)' },
        },
        pendingCriticalPulse: {
          '0%, 100%': { backgroundColor: 'rgb(254 226 226 / 1)' },
          '50%': { backgroundColor: 'rgb(252 165 165 / 0.85)' },
        },
        pendingCriticalPulseDark: {
          '0%, 100%': { backgroundColor: 'rgb(239 68 68 / 0.08)' },
          '50%': { backgroundColor: 'rgb(239 68 68 / 0.24)' },
        },
        // Grows a bar from nothing up to its data value — the target height is
        // supplied per-element via the --bar-height custom property (see the
        // LATIN HONORS BY TYPE chart on admin/dashboard.php).
        growBar: {
          '0%': { height: '0%' },
          '100%': { height: 'var(--bar-height)' },
        },
        // Drifts an element horizontally across its container, starting/ending
        // fully off-screen on either side — the sidebar's ambient orbs (see
        // includes/sidebar.php), where each orb sets its own animation-duration
        // inline (bigger orb = longer duration = slower, a parallax depth cue).
        orbDrift: {
          '0%': { transform: 'translateX(-30%)' },
          '100%': { transform: 'translateX(330%)' },
        },
      },
      animation: {
        'float-a': 'floatA 22s ease-in-out infinite',
        'float-b': 'floatB 28s ease-in-out infinite',
        'float-c': 'floatC 18s ease-in-out infinite',
        drift: 'driftParticle 9s ease-in-out infinite',
        'fade-in-up': 'fadeInUp 0.7s ease-out 0.15s both',
        'fade-in': 'fadeIn 0.9s ease-out both',
        'drop-in': 'dropIn 0.5s ease-out both',
        'glow-pulse': 'glowPulse 7s ease-in-out infinite',
        'progress-indeterminate': 'progressIndeterminate 1.1s ease-in-out infinite',
        'pending-critical-pulse': 'pendingCriticalPulse 2s ease-in-out infinite',
        'pending-critical-pulse-dark': 'pendingCriticalPulseDark 2s ease-in-out infinite',
        'grow-bar': 'growBar 0.8s ease-out both',
        'orb-drift': 'orbDrift 20s linear infinite',
      },
    },
  },
  plugins: [],
};
