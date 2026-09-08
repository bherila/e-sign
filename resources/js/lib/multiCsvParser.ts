/**
 * Multi-section CSV parser for IB-style CSV files.
 *
 * IB CSV format has sections with varying column structures:
 * - First column: Section name (e.g., "Trades", "Statement", "Financial Instrument Information")
 * - Second column: Row type ("Header" for column names, "Data" for values, "Total", "SubTotal", "Notes")
 * - Remaining columns: Values that vary per section
 *
 * Example:
 * ```
 * Statement,Header,Field Name,Field Value
 * Statement,Data,BrokerName,Interactive Brokers LLC
 * Trades,Header,DataDiscriminator,Asset Category,Currency,Symbol,Date/Time,...
 * Trades,Data,Order,Stocks,USD,BIDU,"2025-09-05, 16:20:00",...
 * ```
 */
import { splitDelimitedText } from '@/lib/splitDelimitedText'

export interface ParsedSection {
  /** Column headers for this section (from Header rows) */
  headers: string[]
  /** Data rows as objects with header keys */
  rows: Record<string, string>[]
  /** Total rows (if any) */
  totals: Record<string, string>[]
  /** SubTotal rows (if any) */
  subTotals: Record<string, string>[]
  /** Notes associated with this section */
  notes: string[]
}

export interface ParsedMultiCsv {
  sections: Record<string, ParsedSection>
  /** Raw rows that couldn't be parsed */
  unparsedRows: string[][]
}

/**
 * Parse a multi-section CSV file into structured sections.
 *
 * @param text Raw CSV text content
 * @param options Parser options
 * @returns Parsed sections with headers, rows, totals, and notes
 */
export function parseMultiSectionCsv(
  text: string,
  options: {
    /** Column index for section name (default: 0) */
    sectionColIndex?: number
    /** Column index for row type (default: 1) */
    rowTypeColIndex?: number
    /** Include raw values as additional `_raw` array in each row */
    includeRaw?: boolean
  } = {}
): ParsedMultiCsv {
  const {
    sectionColIndex = 0,
    rowTypeColIndex = 1,
    includeRaw = false,
  } = options

  const rawRows = splitDelimitedText(text, ',')
  const sections: Record<string, ParsedSection> = {}
  const unparsedRows: string[][] = []

  // Track headers for each section
  const sectionHeaders: Record<string, string[]> = {}

  for (const row of rawRows) {
    if (!row || row.length < 2) {
      continue
    }

    const sectionName = row[sectionColIndex]?.trim()
    const rowType = row[rowTypeColIndex]?.trim()

    if (!sectionName || !rowType) {
      unparsedRows.push(row)
      continue
    }

    // Initialize section if not exists
    if (!sections[sectionName]) {
      sections[sectionName] = {
        headers: [],
        rows: [],
        totals: [],
        subTotals: [],
        notes: [],
      }
    }

    const section = sections[sectionName]
    const dataStartCol = Math.max(sectionColIndex, rowTypeColIndex) + 1
    const dataValues = row.slice(dataStartCol)

    switch (rowType.toLowerCase()) {
      case 'header': {
        // Headers can appear multiple times in IB CSV when column structure changes
        // Store the latest header definition
        sectionHeaders[sectionName] = dataValues
        // Update section headers if this is the first or if we want to track header changes
        if (section.headers.length === 0) {
          section.headers = dataValues
        }
        break
      }

      case 'data': {
        const headers = sectionHeaders[sectionName] || []
        const rowObj = buildRowObject(headers, dataValues, includeRaw)
        section.rows.push(rowObj)
        break
      }

      case 'total': {
        const headers = sectionHeaders[sectionName] || []
        const rowObj = buildRowObject(headers, dataValues, includeRaw)
        section.totals.push(rowObj)
        break
      }

      case 'subtotal': {
        const headers = sectionHeaders[sectionName] || []
        const rowObj = buildRowObject(headers, dataValues, includeRaw)
        section.subTotals.push(rowObj)
        break
      }

      case 'notes': {
        // Notes are typically a single string in the first data column
        const noteText = dataValues.join(',').trim()
        if (noteText) {
          section.notes.push(noteText)
        }
        break
      }

      default:
        // Unknown row type, store in unparsed
        unparsedRows.push(row)
    }
  }

  return { sections, unparsedRows }
}

/**
 * Build a row object from headers and values.
 */
function buildRowObject(
  headers: string[],
  values: string[],
  includeRaw: boolean
): Record<string, string> {
  const obj: Record<string, string> = {}

  for (let i = 0; i < Math.max(headers.length, values.length); i++) {
    const header = headers[i] || `col_${i}`
    const value = values[i] || ''
    obj[header] = value
  }

  if (includeRaw) {
    obj['_raw'] = JSON.stringify(values)
  }

  return obj
}

/**
 * Get a specific section from parsed multi-CSV.
 *
 * @param parsed Parsed multi-CSV result
 * @param sectionName Section name to retrieve
 * @returns Section data or undefined if not found
 */
export function getSection(
  parsed: ParsedMultiCsv,
  sectionName: string
): ParsedSection | undefined {
  return parsed.sections[sectionName]
}

/**
 * Get all section names from parsed multi-CSV.
 *
 * @param parsed Parsed multi-CSV result
 * @returns Array of section names
 */
export function getSectionNames(parsed: ParsedMultiCsv): string[] {
  return Object.keys(parsed.sections)
}

/**
 * Check if a multi-section CSV contains a specific section.
 *
 * @param parsed Parsed multi-CSV result
 * @param sectionName Section name to check
 * @returns True if section exists
 */
export function hasSection(parsed: ParsedMultiCsv, sectionName: string): boolean {
  return sectionName in parsed.sections
}
