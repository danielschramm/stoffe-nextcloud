<template>
	<NcAppSidebar :name="displayName(fabric)"
		subname="Angaben werden im Foto gespeichert (XMP)"
		:background="previewUrl(fabric, 1024)"
		@close="$emit('close')">
		<form class="sidebar" @submit.prevent="save(false)">
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

			<NcSelect v-model="form.fabricType"
				input-label="Stoffart"
				:options="fabricTypes"
				placeholder="Stoffart wählen"
				:clearable="true" />

			<NcSelect v-model="form.category"
				input-label="Kategorie"
				:options="categories"
				placeholder="Kategorie wählen oder neu eintippen"
				:taggable="true"
				:clearable="true" />

			<NcTextArea v-model="form.comment" label="Kommentar" resize="vertical" rows="4" />

			<div class="sidebar__actions">
				<NcButton type="submit" variant="primary" :disabled="saving || !priceValid">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					Speichern
				</NcButton>
				<NcButton v-if="hasNext" :disabled="saving || !priceValid" @click="save(true)">
					<template #icon>
						<ArrowRight :size="20" />
					</template>
					Speichern und weiter
				</NcButton>
			</div>
			<div class="sidebar__actions">
				<NcButton variant="tertiary" :href="filesUrl(fabric)">
					<template #icon>
						<Folder :size="20" />
					</template>
					In Dateien öffnen
				</NcButton>
				<NcButton variant="error" @click="$emit('delete', fabric)">
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
	components: { NcAppSidebar, NcButton, NcLoadingIcon, NcNoteCard, NcSelect, NcTextArea, NcTextField, ArrowRight, ContentSave, Delete, Folder },

	props: {
		fabric: { type: Object, required: true },
		fabricTypes: { type: Array, required: true },
		categories: { type: Array, required: true },
		lastLocation: { type: Object, required: true },
		saving: { type: Boolean, default: false },
		hasNext: { type: Boolean, default: false },
	},

	emits: ['close', 'save', 'delete'],

	data() {
		return { form: {}, prefilled: false }
	},

	computed: {
		priceValid() {
			const price = String(this.form.price ?? '').replace(/[€\s]/g, '')
			return price === '' || /^\d+([.,]\d{1,2})?$/.test(price)
		},
	},

	watch: {
		fabric: {
			immediate: true,
			handler(fabric, previous) {
				if (previous && fabric.id === previous.id) {
					// Saved: the values now come from the photo itself.
					this.prefilled = false
					return
				}
				this.form = Object.fromEntries(FIELDS.map((f) => [f, fabric[f] || '']))
				this.form.price = this.form.price.replace('.', ',')
				// An empty storage location starts with the last one entered.
				this.prefilled = !fabric.room && !fabric.cabinet && !fabric.shelf
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

		save(next) {
			const values = { ...this.form }
			for (const key of FIELDS) {
				values[key] = values[key] ?? ''
			}
			this.$emit('save', { values, next })
		},
	},
}
</script>

<style scoped>
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

.sidebar__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
