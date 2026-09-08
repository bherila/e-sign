import { cva, type VariantProps } from 'class-variance-authority'

import { cn } from '@/lib/utils'

const spinnerVariants = cva('animate-spin rounded-full border-4 border-solid border-current border-r-transparent', {
  variants: {
    size: {
      small: 'h-4 w-4',
      medium: 'h-8 w-8',
      large: 'h-12 w-12',
    },
  },
  defaultVariants: {
    size: 'medium',
  },
})

interface SpinnerProps extends React.HTMLAttributes<HTMLDivElement>, VariantProps<typeof spinnerVariants> {}

export function Spinner({ className, size, ...props }: SpinnerProps) {
  return <div className={cn(spinnerVariants({ size }), className)} {...props} />
}
