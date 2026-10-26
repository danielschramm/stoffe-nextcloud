<template>
	<section class="details"
		:class="{ 'details--dragging': dragging }"
		@dragover.prevent="dragging = canUpload"
		@dragleave.prevent="dragging = false"
		@drop.prevent="onDrop">
		<div class="details__head">
			<h3>
				Detailbilder
				<span v-if="!loading" class="details__count">{{ details.length }}</span>
			</h3>
			<NcButton v-if="canUpload"
				variant="secondary"
				:disabled="uploading"
				@click="$refs.input.click()">
				<template #icon>
					<NcLoadingIcon v-if="uploading" :size="20" />
					<ImagePlus v-else :size="20" />
				</template>
				{{ uploading ? `Lade hoch … ${progress} %` : 'Hinzufügen' }}
			</NcButton>
			<input ref="input"
				type="file"
				accept="image/jpeg"
				multiple
				hidden
				@change="onPick">
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />
		<p v-else-if="details.length === 0" class="details__empty">
			{{ canUpload ? 'Noch keine Detailbilder. Füge Nahaufnahmen einzelner Motive hinzu oder ziehe sie hierher.' : 'Keine Detailbilder.' }}
		</p>
		<ul v-else class="details__grid">
			<li v-for="(detail, index) in details" :key="detail.id">
				<button type="button" class="details__thumb" :title="detail.name" @click="openIndex = index">
					<img :src="previewUrl(detail, 256)" :alt="detail.name" loading="lazy">
				</button>
			</li>
		</ul>

		<NcModal v-if="open"
			:name="`${displayName(fabric)}: ${open.name}`"
			size="large"
			:has-previous="openIndex > 0"
			:has-next="openIndex < details.length - 1"
			@previous="openIndex--"
			@next="openIndex++"
			@close="openIndex = null">
			<div class="details__viewer">
				<img :src="previewUrl(open, 2048)" :alt="open.name">
			</div>
			<template #actions>
				<NcActionLink :href="filesUrl(open, detailPath)">
					<template #icon>
						<Folder :size="20" />
					</template>
					In Dateien öffnen
				</NcActionLink>
				<NcActionButton v-if="open.canDelete" @click="remove(open)">
					<template #icon>
						<Delete :size="20" />
					</template>
					Detailbild löschen
				</NcActionButton>
			</template>
		</NcModal>
	</section>
</template>

<script>
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionLink from '@nextcloud/vue/components/NcActionLink'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import { showError, showSuccess } from '@nextcloud/dialogs'
import Delete from 'vue-material-design-icons/Delete.vue'
import Folder from 'vue-material-design-icons/Folder.vue'
import ImagePlus from 'vue-material-design-icons/ImagePlus.vue'
import * as api from '../api.js'
import { displayName, filesUrl, previewUrl } from '../api.js'

/**
 * Close-ups of single motifs, stored in a folder named like the main photo.
 */
export default {
	name: 'DetailImages',
	components: { NcActionButton, NcActionLink, NcButton, NcLoadingIcon, NcModal, Delete, Folder, ImagePlus },

	props: {
		fabric: { type: Object, required: true },
		folderPath: { type: String, default: '/Stoffe' },
	},

	emits: ['update:count'],

	data() {
		return { details: [], canUpload: false, loading: true, uploading: false, progress: 0, dragging: false, openIndex: null }
	},

	computed: {
		open() {
			return this.openIndex === null ? null : this.details[this.openIndex] ?? null
		},

		detailPath() {
			return this.folderPath.replace(/\/$/, '') + '/' + displayName(this.fabric)
		},
	},

	watch: {
		// A new name means the folder was renamed along with the photo.
		'fabric.id': { immediate: true, handler: 'load' },
		'fabric.name': 'load',
	},

	methods: {
		previewUrl,
		filesUrl,
		displayName,

		async load() {
			const id = this.fabric.id
			this.loading = true
			this.openIndex = null
			try {
				const data = await api.listDetails(id)
				if (id === this.fabric.id) {
					this.details = data.details
					this.canUpload = data.canUpload
				}
			} catch (e) {
				this.details = []
				this.canUpload = false
				showError('Detailbilder konnten nicht geladen werden.')
			} finally {
				this.loading = false
			}
		},

		onPick(event) {
			this.upload([...event.target.files])
			event.target.value = ''
		},

		onDrop(event) {
			this.dragging = false
			if (this.canUpload) {
				this.upload([...event.dataTransfer.files])
			}
		},

		async upload(files) {
			const jpegs = files.filter((f) => f.type === 'image/jpeg')
			if (jpegs.length < files.length) {
				showError('Nur JPEG-Fotos können hochgeladen werden.')
			}
			if (jpegs.length === 0) {
				return
			}
			const id = this.fabric.id
			this.uploading = true
			this.progress = 0
			try {
				const { uploaded, errors } = await api.uploadDetails(id, jpegs, (p) => { this.progress = p })
				if (uploaded.length) {
					showSuccess(uploaded.length === 1 ? '1 Detailbild hinzugefügt' : `${uploaded.length} Detailbilder hinzugefügt`)
				}
				for (const err of errors) {
					showError(`${err.name}: ${err.message}`)
				}
			} catch (e) {
				showError(e.response?.data?.message ?? 'Upload fehlgeschlagen.')
			} finally {
				this.uploading = false
			}
			if (id === this.fabric.id) {
				await this.load()
				this.$emit('update:count', this.details.length)
			}
		},

		async remove(detail) {
			if (!confirm(`Detailbild „${detail.name}“ wirklich löschen? Es landet im Papierkorb.`)) {
				return
			}
			try {
				await api.deleteDetail(this.fabric.id, detail.id)
			} catch (e) {
				showError(e.response?.data?.message ?? 'Löschen fehlgeschlagen.')
				return
			}
			const index = this.details.indexOf(detail)
			this.details = this.details.filter((d) => d.id !== detail.id)
			this.openIndex = this.details.length === 0 ? null : Math.min(index, this.details.length - 1)
			this.$emit('update:count', this.details.length)
		},
	},
}
</script>

<style scoped>
.details {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border-radius: var(--border-radius-large);
	outline: 2px dashed transparent;
}

.details--dragging {
	outline-color: var(--color-primary-element);
}

.details__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.details__head h3 {
	margin: 0;
	font-size: 1em;
	font-weight: bold;
}

.details__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	margin-inline-start: 4px;
}

.details__empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.details__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(88px, 1fr));
	gap: 6px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.details__thumb {
	display: block;
	width: 100%;
	aspect-ratio: 1;
	padding: 0;
	margin: 0;
	border: none;
	border-radius: var(--border-radius);
	overflow: hidden;
	cursor: pointer;
	background: var(--color-background-dark);
}

.details__thumb img {
	width: 100%;
	height: 100%;
	object-fit: cover;
	display: block;
}

.details__thumb:focus-visible {
	outline: 2px solid var(--color-primary-element);
}

.details__viewer {
	display: flex;
	justify-content: center;
	align-items: center;
	padding: 12px;
	min-height: 60vh;
}

.details__viewer img {
	max-width: 100%;
	max-height: 80vh;
	object-fit: contain;
}
</style>
