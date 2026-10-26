<template>
	<NcActions :force-name="true"
		:menu-name="folder ? folder.name : 'Ordner wählen'"
		:aria-label="`Ordner: ${folder ? folder.path : 'keiner'}. Anderen Ordner wählen`"
		@open="load">
		<template #icon>
			<FolderAccount v-if="folder && folder.shared" :size="20" />
			<FolderOpen v-else :size="20" />
		</template>
		<NcActionCaption name="Sammlung wählen" />
		<NcActionButton v-for="candidate in candidates"
			:key="candidate.path"
			:model-value="folder && candidate.path === folder.path"
			type="radio"
			close-after-click
			@click="$emit('change', candidate.path)">
			{{ candidate.path }}
			<template v-if="candidate.shared">
				(geteilt von {{ candidate.ownerName }}{{ candidate.canEdit ? '' : ', nur lesen' }})
			</template>
		</NcActionButton>
		<NcActionButton close-after-click @click="pick">
			<template #icon>
				<FolderSwap :size="20" />
			</template>
			Anderen Ordner wählen …
		</NcActionButton>
	</NcActions>
</template>

<script>
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActions from '@nextcloud/vue/components/NcActions'
import { getFilePickerBuilder, showError } from '@nextcloud/dialogs'
import FolderAccount from 'vue-material-design-icons/FolderAccount.vue'
import FolderOpen from 'vue-material-design-icons/FolderOpen.vue'
import FolderSwap from 'vue-material-design-icons/FolderSwap.vue'
import { listFolders } from '../api.js'

export default {
	name: 'FolderSwitcher',
	components: { NcActionButton, NcActionCaption, NcActions, FolderAccount, FolderOpen, FolderSwap },

	props: {
		folder: { type: Object, default: null },
	},

	emits: ['change'],

	data() {
		return { candidates: [] }
	},

	methods: {
		async load() {
			try {
				this.candidates = (await listFolders()).folders
			} catch (e) {
				showError('Ordnerliste konnte nicht geladen werden.')
			}
		},

		async pick() {
			try {
				const path = await getFilePickerBuilder('Ordner für die Stoffe wählen')
					.setMultiSelect(false)
					.allowDirectories(true)
					.setMimeTypeFilter(['httpd/unix-directory'])
					.startAt(this.folder?.path ?? '/')
					.addButton({ label: 'Diesen Ordner verwenden', variant: 'primary', callback: () => {} })
					.build()
					.pick()
				if (path) {
					this.$emit('change', path)
				}
			} catch (e) {
				// Picker closed without choosing.
			}
		},
	},
}
</script>
