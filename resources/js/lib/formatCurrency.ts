export function formatFriendlyAmount(amount: number): string {
  const absAmount = Math.abs(amount)
  if (absAmount >= 1000000) {
    const millions = amount / 1000000
    return millions % 1 === 0 ? `${millions}m` : `${millions.toFixed(1)}m`
  } else if (absAmount >= 1000) {
    const thousands = amount / 1000
    return thousands % 1 === 0 ? `${thousands}k` : `${thousands.toFixed(1)}k`
  }
  return amount.toFixed(0)
}
