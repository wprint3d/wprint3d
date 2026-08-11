import axios from "axios";

axios.defaults.withCredentials = true;
axios.defaults.headers         = { Accept: 'application/json' };

const BACKEND_BASE_URL = '/backend';

export const setBackendLocale = (locale) => {
    if (!locale) {
        delete axios.defaults.headers.common?.["Accept-Language"];
        delete axios.defaults.headers.common?.["X-WPrint3D-Locale"];
        return;
    }

    const normalizedLocale = String(locale).replace("_", "-");

    axios.defaults.headers.common = axios.defaults.headers.common || {};
    axios.defaults.headers.common["Accept-Language"] = normalizedLocale;
    axios.defaults.headers.common["X-WPrint3D-Locale"] = normalizedLocale;
};

export default ({
    get: (url, data, options = {}) => (
        axios.get(
            `${BACKEND_BASE_URL}${url}`, // url
            {
                params: data,
                paramsSerializer: { indexes: true },
                ...options
            }
        )
    ),
    post: (url, data, options = {}) => (
        axios.postForm(
            `${BACKEND_BASE_URL}${url}`, // url
            data,                        // data
            options
        )
    ),
    delete: (url, data) => (
        axios.delete(
            `${BACKEND_BASE_URL}${url}`, // url
            {
                params: data,
                paramsSerializer: { indexes: true }
            }
        )
    ),
    put: (url, data) => (
        axios.put(
            `${BACKEND_BASE_URL}${url}`, // url
            data                         // data
        )
    )
});
