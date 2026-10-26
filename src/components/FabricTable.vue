<template>
	<div class="table">
		<div class="table__toolbar">
			<span class="table__hint">
				Klick auf eine Spaltenüberschrift sortiert, mit gedrückter Umschalttaste kommt eine weitere Spalte dazu.
			</span>
			<NcButton v-if="sort.length || hasColumnFilter" variant="tertiary" @click="resetTable">
				Sortierung und Spaltenfilter zurücksetzen
			</NcButton>
			<NcActions :force-name="true" menu-name="Spalten">
				<template #icon>
					<ViewColumn :size="20" />
				</template>
				<NcActionCheckbox v-for="column in configurable"
					:key="column.key"
					:model-value="visibleKeys.includes(column.key)"
					@update:model-value="toggleColumn(column.key, $event)">
					{{ column.label }}
				</NcActionCheckbox>
			</NcActions>
		</div>

		<div class="table__scroll">
			<table>
				<thead>
					<tr>
						<th v-for="column in columns"
							:key="column.key"
							:class="['table__head', `table__col--${column.type}`]"
							:aria-sort="ariaSort(column.key)">
							<button v-if="column.type !== 'image'"
								class="table__sort"
								:title="`Nach ${column.label} sortieren`"
								@click="onSort(column.key, $event.shiftKey)">
								{{ column.label }}
								<span v-if="sortIndex(column.key) >= 0" class="table__sort-mark">
									<SortAscending v-if="sort[sortIndex(column.key)].dir === 'asc'" :size="16" />
									<SortDescending v-else :size="16" />
									<sup v-if="sort.length > 1">{{ sortIndex(column.key) + 1 }}</sup>
								</span>
							</button>
						</th>
					</tr>
					<tr class="table__filters">
						<th v-for="column in columns" :key="column.key">
							<select v-if="column.type === 'bool'"
								v-model="columnFilter[column.key]"
								:aria-label="`Filter ${column.label}`">
								<option value="">
									Alle
								</option>
								<option value="Ja">
									Ja
								</option>
								<option value="Nein">
									Nein
								</option>
							</select>
							<input v-else-if="column.type !== 'image'"
								v-model="columnFilter[column.key]"
								type="search"
								:aria-label="`Filter ${column.label}`"
								:placeholder="column.type === 'number' ? 'z. B. >100' : 'Filter'">
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="fabric in rows"
						:key="fabric.id"
						:class="{ 'table__row--active': fabric.id === selectedId }"
						tabindex="0"
						@click="$emit('select', fabric.id)"
						@keydown.enter="$emit('select', fabric.id)">
						<td v-for="column in columns" :key="column.key" :class="`table__col--${column.type}`">
							<img v-if="column.type === 'image'"
								:src="previewUrl(fabric, 96)"
								alt=""
								loading="lazy"
								class="table__thumb">
							<template v-else>
								{{ display(column, fabric) }}
							</template>
						</td>
					</tr>
				</tbody>
			</table>
			<p v-if="rows.length === 0" class="table__empty">
				Kein Stoff passt zu den Spaltenfiltern.
			</p>
		</div>
	</div>
</template>

<script>
import NcActionCheckbox from '@nextcloud/vue/components/NcActionCheckbox'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import SortAscending from 'vue-material-design-icons/SortAscending.vue'
import SortDescending from 'vue-material-design-icons/SortDescending.vue'
import ViewColumn from 'vue-material-design-icons/ViewColumn.vue'
import { displayName, formatPrice, previewUrl } from '../api.js'

const COLUMNS = [
	{ key: 'image', label: 'Bild', type: 'image' },
	{ key: 'title', label: 'Titel', type: 'text', value: (f) => displayName(f) },
	{ key: 'fabricType', label: 'Stoffart', type: 'text' },
	{ key: 'category', label: 'Kategorie', type: 'text' },
	{ key: 'room', label: 'Raum', type: 'text' },
	{ key: 'cabinet', label: 'Schrank', type: 'text' },
	{ key: 'shelf', label: 'Fach', type: 'text' },
	{ key: 'price', label: 'Preis', type: 'number', format: formatPrice },
	{ key: 'length', label: 'Länge (cm)', type: 'number' },
	{ key: 'width', label: 'Breite (cm)', type: 'number' },
	{ key: 'remnant', label: 'Reststück', type: 'bool', value: (f) => (f.remnant === 'true' ? 'Ja' : 'Nein') },
	{ key: 'comment', label: 'Kommentar', type: 'text' },
	{ key: 'mtime', label: 'Geändert', type: 'date', value: (f) => f.mtime, format: (v) => new Date(v * 1000).toLocaleDateString('de-DE') },
]

const DEFAULT_KEYS = ['image', 'title', 'fabricType', 'category', 'room', 'cabinet', 'shelf', 'price', 'length', 'width', 'remnant']
const STORAGE_KEY = 'stoffe:table'

const valueOf = (column, fabric) => (column.value ? column.value(fabric) : fabric[column.key] ?? '')
const toNumber = (value) => {
	const n = parseFloat(String(value).replace(',', '.'))
	return Number.isNaN(n) ? null : n
}

/**
 * Number filters understand "150" (exactly), ">100", ">=100", "<50", "<=50"
 * and ranges like "100-200"; anything else is matched as text.
 */
const matchesNumber = (value, filter) => {
	const n = toNumber(value)
	const range = filter.match(/^(\d+(?:[.,]\d+)?)\s*-\s*(\d+(?:[.,]\d+)?)$/)
	if (range) {
		return n !== null && n >= toNumber(range[1]) && n <= toNumber(range[2])
	}
	const compare = filter.match(/^(>=|<=|>|<|=)?\s*(\d+(?:[.,]\d+)?)$/)
	if (!compare) {
		return String(value).toLowerCase().includes(filter.toLowerCase())
	}
	const limit = toNumber(compare[2])
	if (n === null) {
		return false
	}
	return { '>=': n >= limit, '<=': n <= limit, '>': n > limit, '<': n < limit }[compare[1]] ?? n === limit
}

const loadState = () => {
	try {
		return JSON.parse(window.localStorage.getItem(STORAGE_KEY)) ?? {}
	} catch (e) {
		return {}
	}
}

export default {
	name: 'FabricTable',
	components: { NcActionCheckbox, NcActions, NcButton, SortAscending, SortDescending, ViewColumn },

	props: {
		fabrics: { type: Array, required: true },
		selectedId: { type: Number, default: null },
	},

	emits: ['select', 'update:order'],

	data() {
		const state = loadState()
		const known = COLUMNS.map((c) => c.key)
		const keys = Array.isArray(state.columns) ? state.columns.filter((k) => known.includes(k)) : DEFAULT_KEYS
		return {
			visibleKeys: keys.includes('title') ? keys : ['title', ...keys],
			sort: Array.isArray(state.sort) ? state.sort.filter((s) => known.includes(s.key)) : [],
			columnFilter: Object.fromEntries(known.map((k) => [k, ''])),
		}
	},

	computed: {
		configurable() {
			return COLUMNS.filter((c) => c.key !== 'title')
		},

		columns() {
			return COLUMNS.filter((c) => this.visibleKeys.includes(c.key))
		},

		hasColumnFilter() {
			return Object.values(this.columnFilter).some((v) => v.trim() !== '')
		},

		rows() {
			const active = COLUMNS.filter((c) => this.columnFilter[c.key]?.trim())
			const filtered = this.fabrics.filter((fabric) => active.every((column) => {
				const filter = this.columnFilter[column.key].trim()
				const value = valueOf(column, fabric)
				if (column.type === 'number') {
					return matchesNumber(value, filter)
				}
				const text = column.format && value !== '' ? column.format(value) : String(value)
				return column.type === 'bool' ? text === filter : text.toLowerCase().includes(filter.toLowerCase())
			}))
			if (this.sort.length === 0) {
				return filtered
			}
			return [...filtered].sort((a, b) => {
				for (const { key, dir } of this.sort) {
					const column = COLUMNS.find((c) => c.key === key)
					const result = this.compare(column, valueOf(column, a), valueOf(column, b))
					if (result !== 0) {
						return dir === 'asc' ? result : -result
					}
				}
				return 0
			})
		},
	},

	watch: {
		visibleKeys() {
			this.saveState()
		},
		sort: {
			deep: true,
			handler() {
				this.saveState()
			},
		},
		rows: {
			immediate: true,
			handler(rows) {
				// The sidebar's "save and next" follows the table's order.
				this.$emit('update:order', rows.map((f) => f.id))
			},
		},
	},

	methods: {
		previewUrl,

		display(column, fabric) {
			const value = valueOf(column, fabric)
			if (value === '' || value === null) {
				return ''
			}
			if (column.format) {
				return column.format(value)
			}
			return column.type === 'number' ? String(value).replace('.', ',') : value
		},

		/**
		 * Empty values always go last, whatever the direction.
		 */
		compare(column, a, b) {
			const emptyA = a === '' || a === null
			const emptyB = b === '' || b === null
			if (emptyA || emptyB) {
				return emptyA === emptyB ? 0 : (emptyA ? 1 : -1)
			}
			if (column.type === 'number' || column.type === 'date') {
				return toNumber(a) - toNumber(b)
			}
			return String(a).localeCompare(String(b), 'de', { numeric: true, sensitivity: 'base' })
		},

		sortIndex(key) {
			return this.sort.findIndex((s) => s.key === key)
		},

		ariaSort(key) {
			const index = this.sortIndex(key)
			if (index < 0) {
				return 'none'
			}
			return this.sort[index].dir === 'asc' ? 'ascending' : 'descending'
		},

		/**
		 * A click sorts by this column only (ascending, descending, off);
		 * with Shift the column is added to or toggled within the sort order.
		 */
		onSort(key, add) {
			const index = this.sortIndex(key)
			if (!add) {
				if (index >= 0 && this.sort.length === 1) {
					this.sort = this.sort[0].dir === 'asc' ? [{ key, dir: 'desc' }] : []
				} else {
					this.sort = [{ key, dir: 'asc' }]
				}
				return
			}
			if (index < 0) {
				this.sort = [...this.sort, { key, dir: 'asc' }]
			} else if (this.sort[index].dir === 'asc') {
				this.sort = this.sort.map((s, i) => (i === index ? { key, dir: 'desc' } : s))
			} else {
				this.sort = this.sort.filter((_, i) => i !== index)
			}
		},

		toggleColumn(key, show) {
			this.visibleKeys = show
				? COLUMNS.map((c) => c.key).filter((k) => k === key || this.visibleKeys.includes(k))
				: this.visibleKeys.filter((k) => k !== key)
		},

		resetTable() {
			this.sort = []
			for (const key of Object.keys(this.columnFilter)) {
				this.columnFilter[key] = ''
			}
		},

		saveState() {
			try {
				window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ columns: this.visibleKeys, sort: this.sort }))
			} catch (e) {
				// Not saved (e.g. private mode): the table still works.
			}
		},
	},
}
</script>

<style scoped>
.table__toolbar {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.table__hint {
	flex: 1;
	color: var(--color-text-maxcontrast);
}

.table__scroll {
	overflow-x: auto;
}

table {
	width: 100%;
	border-collapse: collapse;
}

th,
td {
	padding: 4px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
	white-space: nowrap;
}

td.table__col--text {
	max-width: 260px;
	overflow: hidden;
	text-overflow: ellipsis;
}

.table__col--number,
.table__col--date {
	text-align: end;
}

.table__sort {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	padding: 4px 0;
	margin: 0;
	border: none;
	background: none;
	font-weight: bold;
	cursor: pointer;
	min-height: 0;
}

.table__sort-mark {
	display: inline-flex;
	align-items: center;
	color: var(--color-primary-element);
}

.table__filters th {
	padding-top: 0;
}

.table__filters input,
.table__filters select {
	width: 100%;
	min-width: 70px;
	margin: 0;
	height: 32px;
	min-height: 32px;
}

tbody tr {
	cursor: pointer;
}

tbody tr:hover,
tbody tr:focus-visible {
	background: var(--color-background-hover);
}

.table__row--active {
	background: var(--color-primary-element-light);
}

.table__thumb {
	width: 48px;
	height: 48px;
	object-fit: cover;
	border-radius: var(--border-radius);
	display: block;
}

.table__empty {
	padding: 16px;
	color: var(--color-text-maxcontrast);
}
</style>
