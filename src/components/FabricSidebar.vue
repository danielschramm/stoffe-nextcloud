<template>
	<NcAppSidebar :name="displayName(fabric)"
		subname="Angaben werden im Foto gespeichert (XMP)"
		:background="previewUrl(fabric, 1024)"
		@close="$emit('close')">
		<form class="sidebar" @submit.prevent="save(false)">
			<NcNoteCard v-if="!fabric.canEdit" type="info" class="sidebar__hint">
				Dieses Foto kannst du nur ansehen. Zum Ändern brauchst du Bearbeitungsrechte für den geteilten Ordner.
			</NcNoteCard>
			<fieldset class="sidebar__fields" :disabled="!fabric.canEdit">
			<NcTextField v-model="title"
				label="Titel (Dateiname)"
				:error="!titleValid"
				:helper-text="titleValid ? '' : 'Bitte einen Titel eingeben.'" />

			<fieldset class="sidebar__group">
				<legend>Lagerort</legend>
				<NcNoteCard v-if="prefilled" type="info" class="sidebar__hint">
					Vorausgefüllt mit deiner letzten Eingabe.
				</NcNoteCard>
				<NcTextField v-model="form.room" label="Raum" />
				<NcTextField v-model="form.cabinet" label="Schrank" />
				<NcTextField v-model="form.shelf" label="Fach" />
			</fieldset>

			<NcTextField v-model="form.price"
				label="Preis (€)"
				inputmode="decimal"
				:error="!priceValid"
				:helper-text="priceValid ? '' : 'Bitte eine Zahl eingeben, z. B. 12,50'" />

			<fieldset class="sidebar__group">
				<legend>Abmessung</legend>
				<div class="sidebar__row">
					<NcTextField v-model="form.length"
						label="Länge (cm)"
						inputmode="decimal"
						:error="!numberValid(form.length)"
						:helper-text="numberValid(form.length) ? '' : 'Zahl, z. B. 150 oder 37,5'" />
					<NcTextField v-model="form.width"
						label="Breite (cm)"
						inputmode="decimal"
						:error="!numberValid(form.width)"
						:helper-text="numberValid(form.width) ? '' : 'Zahl, z. B. 140 oder 37,5'" />
				</div>
				<NcCheckboxRadioSwitch v-model="form.remnant" type="switch" :disabled="!fabric.canEdit">
					Reststück
				</NcCheckboxRadioSwitch>
			</fieldset>

			<NcSelect v-model="form.fabricType"
				input-label="Stoffart"
				:disabled="!fabric.canEdit"
				:options="fabricTypes"
				placeholder="Stoffart wählen"
				:clearable="true" />

			<NcSelect v-model="form.category"
				input-label="Kategorie"
				:disabled="!fabric.canEdit"
				:options="categories"
				placeholder="Kategorie wählen oder neu eintippen"
				:taggable="true"
				:clearable="true" />

			<NcTextArea v-model="form.comment" label="Kommentar" resize="vertical" rows="4" />
			</fieldset>

			<div v-if="fabric.canEdit" class="sidebar__actions">
				<NcButton type="submit" variant="primary" :disabled="saving || !formValid || !titleValid">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					Speichern
				</NcButton>
				<NcButton v-if="hasNext" :disabled="saving || !formValid || !titleValid" @click="save(true)">
					<template #icon>
						<ArrowRight :size="20" />
					</template>
					Speichern und weiter
				</NcButton>
			</div>
			<div class="sidebar__actions">
				<NcButton variant="tertiary" :href="filesUrl(fabric, folderPath)">
					<template #icon>
						<Folder :size="20" />
					</template>
					In Dateien öffnen
				</NcButton>
				<NcButton v-if="fabric.canDelete" variant="error" @click="$emit('delete', fabric)">
					<template #icon>
						<Delete :size="20" />
					</template>
					Löschen
				</NcButton>
			</div>
		</form>
	</NcAppSidebar>
</template>

<script>
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import { displayName, FIELDS, filesUrl, previewUrl } from '../api.js'

export default {
	name: 'FabricSidebar',
	components: { NcAppSidebar, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcSelect, NcTextArea, NcTextField, ArrowRight, ContentSave, Delete, Folder },

	props: {
		fabric: { type: Object, required: true },
		fabricTypes: { type: Array, required: true },
		categories: { type: Array, required: true },
		lastLocation: { type: Object, required: true },
		saving: { type: Boolean, default: false },
		hasNext: { type: Boolean, default: false },
		folderPath: { type: String, default: '/Stoffe' },
	},

	emits: ['close', 'save', 'delete'],

	data() {
		return { form: {}, title: '', prefilled: false }
	},

	computed: {
		titleValid() {
			return this.title.trim() !== '' && !this.title.trim().startsWith('.')
		},

		priceValid() {
			const price = String(this.form.price ?? '').replace(/[€\s]/g, '')
			return price === '' || /^\d+([.,]\d{1,2})?$/.test(price)
		},

		formValid() {
			return this.priceValid && this.numberValid(this.form.length) && this.numberValid(this.form.width)
		},
	},

	watch: {
		fabric: {
			immediate: true,
			handler(fabric, previous) {
				if (previous && fabric.id === previous.id) {
					// Saved: the values now come from the photo itself.
					this.prefilled = false
					this.title = displayName(fabric)
					return
				}
				this.title = displayName(fabric)
				this.form = Object.fromEntries(FIELDS.map((f) => [f, fabric[f] || '']))
				for (const key of ['price', 'length', 'width']) {
					this.form[key] = this.form[key].replace('.', ',')
				}
				this.form.remnant = fabric.remnant === 'true'
				// An empty storage location starts with the last one entered.
				this.prefilled = fabric.canEdit && !fabric.room && !fabric.cabinet && !fabric.shelf
					&& Object.values(this.lastLocation).some(Boolean)
				if (this.prefilled) {
					Object.assign(this.form, this.lastLocation)
				}
			},
		},
	},

	methods: {
		previewUrl,
		filesUrl,
		displayName,

		numberValid(value) {
			const number = String(value ?? '').trim()
			return number === '' || /^\d+([.,]\d)?$/.test(number)
		},

		save(next) {
			const values = { ...this.form }
			for (const key of FIELDS) {
				values[key] = values[key] ?? ''
			}
			values.remnant = this.form.remnant ? 'true' : 'false'
			this.$emit('save', { values, title: this.title.trim(), next })
		},
	},
}
</script>

<style scoped>
.sidebar__fields {
	display: flex;
	flex-direction: column;
	gap: 12px;
	border: none;
	padding: 0;
	margin: 0;
	min-width: 0;
}

.sidebar {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 12px 24px;
}

.sidebar__group {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border: none;
	padding: 0;
	margin: 0;
}

.sidebar__group legend {
	font-weight: bold;
	margin-bottom: 4px;
}

.sidebar__hint {
	margin: 0 !important;
}

.sidebar__row {
	display: flex;
	gap: 8px;
}

.sidebar__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
