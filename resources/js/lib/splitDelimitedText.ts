/**
 * Splits a delimited text file into rows and columns.
 * Auto-detects delimiter from first line if not provided.
 * Handles quoted columns, escaped characters, and newlines within quoted strings.
 *
 * @param text Delimited text file content.
 * @param delimiter Delimiter character to split by (optional).
 * @returns 2D array of split values.
 */
export function splitDelimitedText(text: string, delimiter?: string): string[][] {
  if (!text) {
    return []
  }

  // Auto-detect delimiter from first line
  if (!delimiter) {
    const firstLine = text.split('\n')[0]
    let foundDelimiter = ','

    if (firstLine) {
      // Check for delimiters outside of quoted sections
      let inQuotes = false

      for (let i = 0; i < firstLine.length; i++) {
        const char = firstLine[i]
        if (char === '"') {
          inQuotes = !inQuotes
          continue
        }
        if (!inQuotes) {
          if (char === '\t') {
            foundDelimiter = '\t'
            break
          } else if (char === ',') {
            foundDelimiter = ','
            break
          } else if (char === '|') {
            foundDelimiter = '|'
            break
          }
        }
      }
    }

    delimiter = foundDelimiter
  }

  const rows: string[][] = []
  let currentRow: string[] = []
  let currentCol = ''
  let inQuotes = false
  let preserveNextNewline = false

  for (let i = 0; i < text.length; i++) {
    const char = text[i]

    // Handle escaped characters
    if (char === '\\') {
      i++
      if (i < text.length) {
        currentCol += text[i]
      }
      continue
    }

    // Handle quoted columns
    if (char === '"') {
      inQuotes = !inQuotes
      if (inQuotes && i > 0 && text[i - 1] === '\n') {
        preserveNextNewline = true
      }
      continue
    }

    // Handle delimiter when not in quotes
    if (!inQuotes && char === delimiter) {
      currentRow.push(currentCol.trim())
      currentCol = ''
      continue
    }

    // Handle newline
    if (char === '\n') {
      if (inQuotes) {
        if (preserveNextNewline) {
          currentCol += '\n'
          preserveNextNewline = false
        } else {
          currentCol += ' '
        }
        continue
      }

      currentRow.push(currentCol.trim())
      if (currentRow.some((col) => col.trim())) {
        rows.push(currentRow)
      }
      currentRow = []
      currentCol = ''
      continue
    }

    // Add character to current column
    currentCol += char
  }

  // Add final row if there's data
  if (currentCol !== '' || currentRow.length > 0) {
    currentRow.push(currentCol.trim())
    if (currentRow.some((col) => col.trim())) {
      rows.push(currentRow)
    }
  }

  return rows
}
