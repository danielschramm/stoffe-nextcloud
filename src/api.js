import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const api = (path) => generateUrl('/apps/stoffe/api' + path)

export const FIELDS = ['room', 'cabinet', 'shelf', 'price', 'length', 'width', 'remnant', 'fabricType', 'category', 'comment']

export async function listFabrics() {
	const { data } = await axios.get(api('/fabrics'))
	return data
}

export async function uploadFabrics(files, onProgress) {
	const form = new FormData()
	for (const file of files) {
		form.append('photos[]', file, file.name)
	}
	const { data } = await axios.post(api('/fabrics'), form, {
		onUploadProgress: (e) => e.total && onProgress?.(Math.round((e.loaded / e.total) * 100)),
		validateStatus: (s) => s === 201 || s === 400,
	})
	return data
}

export async function updateFabric(id, values) {
	const { data } = await axios.put(api(`/fabrics/${id}`), values)
	return data
}

export async function renameFabric(id, title) {
	const { data } = await axios.post(api(`/fabrics/${id}/rename`), { title })
	return data
}

export async function listFolders() {
	const { data } = await axios.get(api('/folders'))
	return data
}

export async function setFolder(path) {
	const { data } = await axios.put(api('/folder'), { path })
	return data
}

export async function deleteFabric(id) {
	await axios.delete(api(`/fabrics/${id}`))
}

export async function getSettings() {
	const { data } = await axios.get(api('/settings'))
	return data
}

export async function addCategory(name) {
	const { data } = await axios.post(api('/categories'), { name })
	return data
}

export async function renameCategory(from, to) {
	const { data } = await axios.post(api('/categories/rename'), { from, to })
	return data
}

export async function deleteCategory(name) {
	const { data } = await axios.post(api('/categories/delete'), { name })
	return data
}

export function previewUrl(fabric, size = 512) {
	return generateUrl('/core/preview?fileId={id}&x={size}&y={size}&a=1&mimeFallback=1&v={mtime}', {
		id: fabric.id, size, mtime: fabric.mtime,
	})
}

export function filesUrl(fabric, folderPath = '/Stoffe') {
	return generateUrl('/apps/files/?dir={dir}&openfile={id}', { dir: folderPath, id: fabric.id })
}

export function formatPrice(price) {
	return price === '' ? '' : Number(price).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' })
}

export function formatSize(fabric) {
	const fmt = (v) => Number(v).toLocaleString('de-DE')
	if (fabric.length && fabric.width) {
		return `${fmt(fabric.length)} × ${fmt(fabric.width)} cm`
	}
	if (fabric.length || fabric.width) {
		return fabric.length ? `${fmt(fabric.length)} cm lang` : `${fmt(fabric.width)} cm breit`
	}
	return ''
}

export function displayName(fabric) {
	return fabric.name.replace(/\.[^.]+$/, '')
}
