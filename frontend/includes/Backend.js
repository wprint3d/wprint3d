import axios from "axios";

axios.defaults.withCredentials = true;
axios.defaults.headers         = { Accept: 'application/json' };

const BACKEND_BASE_URL = '/backend';

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
    post: (url, data) => (
        axios.postForm(
            `${BACKEND_BASE_URL}${url}`, // url
            data                         // data
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