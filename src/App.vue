<template>
	<NcContent app-name="stoffe">
		<NcAppNavigation aria-label="Filter">
			<template #list>
				<div class="filters">
					<NcTextField v-model="filter.text" label="Suche" placeholder="Name, Kommentar, …" :show-trailing-button="filter.text !== ''" trailing-button-icon="close" @trailing-button-click="filter.text = ''">
						<template #icon>
							<Magnify :size="20" />
						</template>
					</NcTextField>

					<h3 class="filters__caption">Lagerort</h3>
					<NcSelect v-model="filter.room" input-label="Raum" :options="optionsFor('room')" placeholder="Alle" />
					<NcSelect v-model="filter.cabinet" input-label="Schrank" :options="optionsFor('cabinet')" placeholder="Alle" />
					<NcSelect v-model="filter.shelf" input-label="Fach" :options="optionsFor('shelf')" placeholder="Alle" />

					<h3 class="filters__caption">Stoff</h3>
					<NcSelect v-model="filter.fabricType" input-label="Stoffart" :options="optionsFor('fabricType')" placeholder="Alle" />
					<NcSelect v-model="filter.category" input-label="Kategorie" :options="optionsFor('category')" placeholder="Alle" />

					<h3 class="filters__caption">Preis (€)</h3>
					<div class="filters__price">
						<NcTextField v-model="filter.priceMin" label="von" inputmode="decimal" />
						<NcTextField v-model="filter.priceMax" label="bis" inputmode="decimal" />
					</div>

					<h3 class="filters__caption">Abmessung (cm)</h3>
					<div class="filters__price">
						<NcTextField v-model="filter.lengthMin" label="Länge von" inputmode="decimal" />
						<NcTextField v-model="filter.lengthMax" label="Länge bis" inputmode="decimal" />
					</div>
					<div class="filters__price">
						<NcTextField v-model="filter.widthMin" label="Breite von" inputmode="decimal" />
						<NcTextField v-model="filter.widthMax" label="Breite bis" inputmode="decimal" />
					</div>
					<NcSelect v-model="filter.remnant" input-label="Reststück" :options="['Ja', 'Nein']" placeholder="Alle" />

					<NcButton v-if="filterActive" variant="tertiary" wide @click="resetFilter">
						<template #icon>
							<FilterRemove :size="20" />
						</template>
						Filter zurücksetzen
					</NcButton>
				</div>
			</template>
			<template #footer>
				<NcAppNavigationSettings name="Kategorien verwalten">
					<CategoryManager :categories="settings.categories"
						@add="onAddCategory"
						@rename="onRenameCategory"
						@delete="onDeleteCategory" />
				</NcAppNavigationSettings>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<div class="stoffe"
				:class="{ 'stoffe--dragging': dragging }"
				@dragover.prevent="dragging = true"
				@dragleave.prevent="dragging = false"
				@drop.prevent="onDrop">
				<header class="stoffe__header">
					<h2>
						Stoffe
						<span v-if="!loading" class="stoffe__count">
							{{ filterActive ? `${visible.length} von ${fabrics.length}` : fabrics.length }}
						</span>
					</h2>
					<NcButton variant="primary" :disabled="uploading" @click="$refs.input.click()">
						<template #icon>
							<NcLoadingIcon v-if="uploading" :size="20" />
							<Upload v-else :size="20" />
						</template>
						{{ uploading ? `Lade hoch … ${progress} %` : 'Fotos hochladen' }}
					</NcButton>
					<input ref="input"
						type="file"
						accept="image/jpeg"
						multiple
						hidden
						@change="onPick">
				</header>

				<NcLoadingIcon v-if="loading" :size="48" class="stoffe__loading" />

				<NcEmptyContent v-else-if="fabrics.length === 0"
					name="Noch keine Stoffe"
					description="Lade Fotos (JPEG) hoch oder ziehe sie hierher. Sie landen im Ordner „Stoffe“ in deinen Dateien.">
					<template #icon>
						<ImageMultiple />
					</template>
				</NcEmptyContent>

				<NcEmptyContent v-else-if="visible.length === 0" name="Kein Stoff passt zum Filter">
					<template #icon>
						<FilterRemove />
					</template>
					<template #action>
						<NcButton @click="resetFilter">
							Filter zurücksetzen
						</NcButton>
					</template>
				</NcEmptyContent>

				<ul v-else class="stoffe__grid">
					<li v-for="fabric in visible" :key="fabric.id" class="stoffe__item" :class="{ 'stoffe__item--active': selectedId === fabric.id }">
						<button class="stoffe__thumb" :title="fabric.name" @click="selectedId = fabric.id">
							<img :src="previewUrl(fabric)" :alt="fabric.name" loading="lazy">
							<span v-if="fabric.price" class="stoffe__price">{{ formatPrice(fabric.price) }}</span>
							<span v-if="fabric.remnant === 'true'" class="stoffe__badge">Reststück</span>
						</button>
						<span class="stoffe__name">{{ fabric.fabricType || displayName(fabric) }}</span>
						<span class="stoffe__meta">{{ [fabric.category, formatSize(fabric), location(fabric)].filter(Boolean).join(' · ') || 'Noch keine Angaben' }}</span>
					</li>
				</ul>
			</div>
		</NcAppContent>

		<FabricSidebar v-if="selected"
			:fabric="selected"
			:fabric-types="fabricTypeOptions"
			:categories="settings.categories"
			:last-location="settings.lastLocation"
			:saving="saving"
			:has-next="nextId !== null"
			@close="selectedId = null"
			@save="onSave"
			@delete="onDelete" />
	</NcContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationSettings from '@nextcloud/vue/components/NcAppNavigationSettings'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { showError, showSuccess } from '@nextcloud/dialogs'
import FilterRemove from 'vue-material-design-icons/FilterRemove.vue'
import ImageMultiple from 'vue-material-design-icons/ImageMultiple.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import CategoryManager from './components/CategoryManager.vue'
import FabricSidebar from './components/FabricSidebar.vue'
import * as api from './api.js'
import { displayName, formatPrice, formatSize, previewUrl } from './api.js'

import '@nextcloud/dialogs/style.css'

// Filter option that matches photos where the field is still empty.
const EMPTY = '(ohne Angabe)'

const emptyFilter = () => ({
	text: '', room: null, cabinet: null, shelf: null, fabricType: null, category: null, priceMin: '', priceMax: '',
	lengthMin: '', lengthMax: '', widthMin: '', widthMax: '', remnant: null,
})

// Empty bounds match everything; a set bound excludes photos without a value.
const inRange = (value, min, max) => {
	const lo = toNumber(min)
	const hi = toNumber(max)
	if (lo === null && hi === null) {
		return true
	}
	const n = toNumber(value)
	return n !== null && (lo === null || n >= lo) && (hi === null || n <= hi)
}

const toNumber = (value) => {
	const n = parseFloat(String(value).replace(',', '.'))
	return Number.isNaN(n) ? null : n
}

export default {
	name: 'App',
	components: {
		NcAppContent, NcAppNavigation, NcAppNavigationSettings, NcButton, NcContent, NcEmptyContent, NcLoadingIcon, NcSelect, NcTextField,
		FilterRemove, ImageMultiple, Magnify, Upload, CategoryManager, FabricSidebar,
	},

	data() {
		return {
			fabrics: [],
			settings: { categories: [], fabricTypes: [], lastLocation: { room: '', cabinet: '', shelf: '' } },
			filter: emptyFilter(),
			loading: true,
			uploading: false,
			saving: false,
			progress: 0,
			dragging: false,
			selectedId: null,
		}
	},

	computed: {
		selected() {
			return this.fabrics.find((f) => f.id === this.selectedId) ?? null
		},

		visible() {
			const f = this.filter
			const text = f.text.trim().toLowerCase()
			const matches = (fabric, key) => f[key] === null || f[key] === undefined
				|| (f[key] === EMPTY ? fabric[key] === '' : fabric[key] === f[key])

			return this.fabrics.filter((fabric) => {
				if (!['room', 'cabinet', 'shelf', 'fabricType', 'category'].every((key) => matches(fabric, key))) {
					return false
				}
				if (!inRange(fabric.price, f.priceMin, f.priceMax)
					|| !inRange(fabric.length, f.lengthMin, f.lengthMax)
					|| !inRange(fabric.width, f.widthMin, f.widthMax)) {
					return false
				}
				if (f.remnant && (fabric.remnant === 'true') !== (f.remnant === 'Ja')) {
					return false
				}
				if (text) {
					const haystack = [fabric.name, fabric.room, fabric.cabinet, fabric.shelf, fabric.fabricType, fabric.category, fabric.comment]
						.join(' ').toLowerCase()
					return text.split(/\s+/).every((word) => haystack.includes(word))
				}
				return true
			})
		},

		filterActive() {
			return JSON.stringify(this.filter) !== JSON.stringify(emptyFilter())
		},

		fabricTypeOptions() {
			const used = this.fabrics.map((f) => f.fabricType).filter(Boolean)
			return [...new Set([...this.settings.fabricTypes, ...used])]
		},

		nextId() {
			const index = this.visible.findIndex((f) => f.id === this.selectedId)
			return index >= 0 && index < this.visible.length - 1 ? this.visible[index + 1].id : null
		},
	},

	async mounted() {
		try {
			// One after the other: the first call may create the "Stoffe" folder.
			this.fabrics = await api.listFabrics()
			this.settings = await api.getSettings()
		} catch (e) {
			showError('Stoffe konnten nicht geladen werden.')
		} finally {
			this.loading = false
		}
	},

	methods: {
		previewUrl,
		formatPrice,
		formatSize,
		displayName,

		location(fabric) {
			return [fabric.room, fabric.cabinet, fabric.shelf].filter(Boolean).join(' / ')
		},

		/**
		 * Filter choices are the values actually used in the photos. Location
		 * choices narrow down to what exists in the chosen room/cabinet.
		 */
		optionsFor(key) {
			let source = this.fabrics
			if (key === 'cabinet' && this.filter.room) {
				source = source.filter((f) => this.filter.room === EMPTY ? !f.room : f.room === this.filter.room)
			}
			if (key === 'shelf' && this.filter.cabinet) {
				source = source.filter((f) => this.filter.cabinet === EMPTY ? !f.cabinet : f.cabinet === this.filter.cabinet)
			}
			const values = [...new Set(source.map((f) => f[key]).filter(Boolean))]
				.sort((a, b) => a.localeCompare(b, 'de', { numeric: true }))
			return source.some((f) => !f[key]) ? [...values, EMPTY] : values
		},

		resetFilter() {
			this.filter = emptyFilter()
		},

		onPick(event) {
			this.upload([...event.target.files])
			event.target.value = ''
		},

		onDrop(event) {
			this.dragging = false
			this.upload([...event.dataTransfer.files])
		},

		async upload(files) {
			const jpegs = files.filter((f) => f.type === 'image/jpeg')
			if (jpegs.length < files.length) {
				showError('Nur JPEG-Fotos können hochgeladen werden.')
			}
			if (jpegs.length === 0) {
				return
			}
			this.uploading = true
			this.progress = 0
			try {
				const { uploaded, errors } = await api.uploadFabrics(jpegs, (p) => { this.progress = p })
				this.fabrics = [...uploaded, ...this.fabrics]
				if (uploaded.length) {
					showSuccess(uploaded.length === 1 ? '1 Foto hochgeladen' : `${uploaded.length} Fotos hochgeladen`)
					// Open the first new photo so its details can be entered right away.
					this.resetFilter()
					this.selectedId = uploaded[0].id
				}
				for (const err of errors) {
					showError(`${err.name}: ${err.message}`)
				}
			} catch (e) {
				showError('Upload fehlgeschlagen.')
			} finally {
				this.uploading = false
			}
		},

		async onSave({ values, next }) {
			const nextId = this.nextId
			this.saving = true
			try {
				const updated = await api.updateFabric(this.selectedId, values)
				this.replace(updated)
				if (updated.room || updated.cabinet || updated.shelf) {
					this.settings.lastLocation = { room: updated.room, cabinet: updated.cabinet, shelf: updated.shelf }
				}
				if (updated.category && !this.settings.categories.includes(updated.category)) {
					this.settings = await api.getSettings()
				}
				showSuccess('Gespeichert')
				if (next && nextId !== null) {
					this.selectedId = nextId
				}
			} catch (e) {
				showError(e.response?.data?.message ?? 'Speichern fehlgeschlagen.')
			} finally {
				this.saving = false
			}
		},

		async onDelete(fabric) {
			if (!confirm(`„${displayName(fabric)}“ wirklich löschen? Das Foto landet im Papierkorb.`)) {
				return
			}
			try {
				await api.deleteFabric(fabric.id)
				this.fabrics = this.fabrics.filter((f) => f.id !== fabric.id)
				this.selectedId = null
			} catch (e) {
				showError('Löschen fehlgeschlagen.')
			}
		},

		replace(updated) {
			this.fabrics = this.fabrics.map((f) => (f.id === updated.id ? updated : f))
		},

		async onAddCategory(name) {
			this.settings = await api.addCategory(name)
		},

		async onRenameCategory({ from, to }) {
			try {
				this.settings = await api.renameCategory(from, to)
				this.fabrics = await api.listFabrics()
				if (this.filter.category === from) {
					this.filter.category = to
				}
			} catch (e) {
				showError(e.response?.data?.message ?? 'Umbenennen fehlgeschlagen.')
			}
		},

		async onDeleteCategory(name) {
			this.settings = await api.deleteCategory(name)
		},
	},
}
</script>

<style scoped>
.filters {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px;
}

.filters__caption {
	margin: 12px 0 0;
	font-size: 1em;
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.filters__price {
	display: flex;
	gap: 8px;
}

.stoffe {
	padding: 16px 24px;
	min-height: 100%;
	box-sizing: border-box;
}

.stoffe--dragging {
	outline: 3px dashed var(--color-primary-element);
	outline-offset: -8px;
}

.stoffe__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	/* leave room for the app navigation toggle */
	padding-inline-start: 36px;
	margin-bottom: 16px;
}

.stoffe__header h2 {
	margin: 0;
}

.stoffe__count {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
	margin-inline-start: 8px;
}

.stoffe__loading {
	margin-top: 64px;
}

.stoffe__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
	gap: 16px;
	list-style: none;
	padding: 0;
}

.stoffe__item {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.stoffe__thumb {
	position: relative;
	aspect-ratio: 1;
	padding: 0;
	margin: 0 0 4px;
	border: 3px solid transparent;
	border-radius: var(--border-radius-large);
	overflow: hidden;
	cursor: pointer;
	background: var(--color-background-dark);
}

.stoffe__item--active .stoffe__thumb {
	border-color: var(--color-primary-element);
}

.stoffe__thumb img {
	width: 100%;
	height: 100%;
	object-fit: cover;
	display: block;
	transition: transform 0.2s;
}

.stoffe__thumb:hover img,
.stoffe__thumb:focus-visible img {
	transform: scale(1.04);
}

.stoffe__price {
	position: absolute;
	inset-inline-end: 6px;
	bottom: 6px;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-weight: bold;
}

.stoffe__badge {
	position: absolute;
	inset-inline-start: 6px;
	top: 6px;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-warning);
	color: var(--color-warning-text, #000);
	font-weight: bold;
	font-size: 0.85em;
}

.stoffe__name,
.stoffe__meta {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.stoffe__name {
	font-weight: bold;
}

.stoffe__meta {
	color: var(--color-text-maxcontrast);
}
</style>
