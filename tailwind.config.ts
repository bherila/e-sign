export default {
  content: [
    './resources/views/**/*.blade.php',
    './resources/js/**/*.{ts,tsx,js,jsx}',
    './resources/css/**/*.css',
  ],
  theme: {
    extend: {
      borderColor: {
        DEFAULT: 'hsl(var(--border))',
      },
    },
  },
  plugins: [require('@tailwindcss/typography')],
};
