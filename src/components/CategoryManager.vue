<template>
	<div class="categories">
		<ul>
			<li v-for="category in categories" :key="category" class="categories__row">
				<template v-if="editing === category">
					<NcTextField v-model="draft"
						label="Neuer Name"
						:show-trailing-button="true"
						trailing-button-icon="arrowEnd"
						@keyup.enter="rename(category)"
						@keyup.esc="editing = null"
						@trailing-button-click="rename(category)" />
				</template>
				<template v-else>
					<span class="categories__name">{{ category }}</span>
					<NcButton variant="tertiary" :aria-label="`${category} umbenennen`" @click="startEdit(category)">
						<template #icon>
							<Pencil :size="20" />
						</template>
					</NcButton>
					<NcButton variant="tertiary" :aria-label="`${category} löschen`" @click="$emit('delete', category)">
						<template #icon>
							<Delete :size="20" />
						</template>
					</NcButton>
				</template>
			</li>
		</ul>
		<NcTextField v-model="newName"
			label="Neue Kategorie"
			:show-trailing-button="newName !== ''"
			trailing-button-icon="arrowEnd"
			@keyup.enter="add"
			@trailing-button-click="add" />
		<p class="categories__note">
			Umbenennen ändert die Kategorie auch bei allen Fotos. Löschen entfernt sie nur aus der Auswahl, die Fotos behalten ihren Eintrag.
		</p>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Delete from 'vue-material-design-icons/Delete.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'

export default {
	name: 'CategoryManager',
	components: { NcButton, NcTextField, Delete, Pencil },

	props: {
		categories: { type: Array, required: true },
	},

	emits: ['add', 'rename', 'delete'],

	data() {
		return { editing: null, draft: '', newName: '' }
	},

	methods: {
		startEdit(category) {
			this.editing = category
			this.draft = category
		},

		rename(category) {
			if (this.draft.trim() && this.draft.trim() !== category) {
				this.$emit('rename', { from: category, to: this.draft.trim() })
			}
			this.editing = null
		},

		add() {
			if (this.newName.trim()) {
				this.$emit('add', this.newName.trim())
				this.newName = ''
			}
		},
	},
}
</script>

<style scoped>
.categories ul {
	margin-bottom: 8px;
}

.categories__row {
	display: flex;
	align-items: center;
	gap: 2px;
	min-height: 44px;
}

.categories__name {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
}

.categories__note {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
